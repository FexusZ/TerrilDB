<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Audit\AuditedQueries;
use App\Core\Diagnostics\QueryText;
use App\Core\Driver\TargetConnection;
use App\Core\Guard\GuardReason;
use App\Core\Guard\SqlDialect;
use App\Core\Optimizer\MeasureSlots;
use App\Core\Optimizer\PlanAnalyzer;
use App\Core\Optimizer\PlanService;
use App\Core\Optimizer\QueryPlan;
use App\Core\Optimizer\WritePlanAdvisor;
use App\Core\Query\Exception\InvalidQueryOptionsException;
use App\Core\Query\Exception\QueryRefusedException;
use App\Core\Query\Exception\QueryTimeoutException;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryOptions;
use App\Http\Json\JsonInput;
use App\Http\Json\PlanPresenter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Le plan d'exécution estimé d'une lecture. L'EXPLAIN ne lance pas la requête ; il passe par l'exécution ordinaire
 * (limites, audit), le journal gardant l'instruction de l'utilisateur et non l'EXPLAIN construit.
 *
 * `write: true` demande le plan d'une écriture simple (INSERT, UPDATE, DELETE). L'EXPLAIN reste seul (jamais ANALYZE) et
 * passe en lecture-écriture annulée d'office (`discard`) : MySQL et MariaDB refusent d'expliquer une écriture en lecture
 * seule, et le garde de QueryService ne laisse passer que l'EXPLAIN. L'instruction n'est jamais jouée, rien n'est validé.
 *
 * `measure: true` demande la MESURE réelle (EXPLAIN ANALYZE) : la requête est jouée pour de bon, ce que l'utilisateur a demandé
 * explicitement. Seule une lecture simple (le garde juge d'abord), jamais une écriture ni avec `write`, en lecture seule
 * annulée, avec un délai moteur court par défaut (`timeoutMs`, borné). Le journal (`purpose: plan_measured`) ne garde pas le
 * texte de l'utilisateur tel quel mais ses valeurs masquées et coupé à 200 caractères, et jamais le message d'un échec du
 * moteur : il peut en citer un extrait. ClickHouse ne mesure pas : il rend son plan estimé et `measure.status: unsupported`.
 */
final readonly class PlanController
{
    /** Délai moteur d'une mesure quand la demande n'en donne pas : la requête est jouée, mieux vaut la couper vite. */
    public const MEASURE_TIMEOUT_MS = 10_000;
    /** Plafond d'un délai demandé (sous la durée maximale d'une requête web, 100 s par défaut). */
    public const MEASURE_MAX_TIMEOUT_MS = 100_000;

    public function __construct(
        private TargetAccess $targets,
        private AuditedQueries $queries,
        private PlanService $plans,
        private PlanAnalyzer $analyzer,
        private WritePlanAdvisor $writeAdvisor,
        private MeasureSlots $slots,
        private WebSession $session,
    ) {
    }

    #[Route('/web/connections/{id}/plan', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function plan(string $id, Request $request): JsonResponse
    {
        $input = JsonInput::fromRequest($request);
        $sql = $input->string('sql');
        $write = $input->bool('write', false);
        $measure = $input->bool('measure', false);
        $timeout = $measure ? $input->optionalInt('timeoutMs', self::MEASURE_TIMEOUT_MS) ?? self::MEASURE_TIMEOUT_MS : null;
        if (null !== $timeout && ($timeout < QueryOptions::MIN_TIMEOUT_MS || $timeout > self::MEASURE_MAX_TIMEOUT_MS)) {
            throw new InvalidQueryOptionsException('The timeout is out of bounds.');
        }
        $uuid = Uuid::fromString($id);

        $engine = null;
        $measured = false;
        $plan = $this->targets->withSaved($uuid, function (TargetConnection $target) use ($sql, $uuid, $write, $timeout, &$engine, &$measured) {
            $engine = $target->engine;
            $measured = null !== $timeout && $this->plans->canMeasure($target->engine);
            if ($measured) {
                $context = $this->session->auditContext();

                // Une mesure rejoue la requête et tient un processus jusqu'au délai : deux à la fois par compte, place rendue en toute fin.
                return $this->slots->run((string) ($context->userId ?? $context->ip), fn (): QueryPlan => $this->measured($uuid, $target, $sql, $write, (int) $timeout));
            }

            $statement = $write ? $this->plans->statementForWrite($target->engine, $sql) : $this->plans->statementFor($target->engine, $sql);
            $result = $this->queries->execute(
                $this->session->auditContext(),
                $uuid,
                $target,
                $statement,
                $write
                    ? new QueryOptions(QueryMode::ReadWrite, (new QueryOptions())->timeoutMs, 10, discard: true)
                    : new QueryOptions(QueryMode::ReadOnly, (new QueryOptions())->timeoutMs, 10),
                extra: ['purpose' => 'plan'],
                recordedSql: $sql,
            );

            return $this->plans->parse($target->engine, $result);
        });

        $findings = $this->analyzer->analyze($plan, $engine, $sql);
        if ($write && null !== $engine) {
            return new JsonResponse(PlanPresenter::presentWrite($plan, $findings, $this->writeAdvisor->advise($plan, $engine)));
        }

        $body = PlanPresenter::present($plan, $findings);
        if (null !== $timeout) {
            $body['measure'] = $measured ? ['status' => 'measured'] : ['status' => 'unsupported', 'reason' => 'engine'];
        }

        return new JsonResponse($body);
    }

    /**
     * Jouer la lecture pour la mesurer. `write` avec `measure` est un refus net : une écriture ne se mesure jamais.
     */
    private function measured(Uuid $uuid, TargetConnection $target, string $sql, bool $write, int $timeoutMs): QueryPlan
    {
        if ($write) {
            throw new QueryRefusedException(GuardReason::NotReadOnly);
        }

        $text = QueryText::of($sql, SqlDialect::forEngine($target->engine));
        $result = $this->queries->execute(
            $this->session->auditContext(),
            $uuid,
            $target,
            $this->plans->measuredStatementFor($target->engine, $sql),
            new QueryOptions(QueryMode::ReadOnly, $timeoutMs, 10),
            extra: ['purpose' => 'plan_measured', 'sqlTruncated' => $text->shortened, 'timeoutMs' => $timeoutMs],
            recordedSql: $text->text ?? '',
            recordEngineError: false,
        );

        // MySQL ne lève pas d'erreur quand son délai coupe un EXPLAIN ANALYZE : il rend le plan interrompu, aux lignes partielles. Une
        // mesure qui a pris tout le délai n'est pas fiable, elle n'est pas montrée.
        if ($result->durationMs >= $timeoutMs) {
            throw QueryTimeoutException::afterMilliseconds($timeoutMs);
        }

        return $this->plans->parseMeasured($target->engine, $result);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Audit\AuditedQueries;
use App\Core\Driver\TargetConnection;
use App\Core\Optimizer\PlanAnalyzer;
use App\Core\Optimizer\PlanService;
use App\Core\Optimizer\WritePlanAdvisor;
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
 */
final readonly class PlanController
{
    public function __construct(
        private TargetAccess $targets,
        private AuditedQueries $queries,
        private PlanService $plans,
        private PlanAnalyzer $analyzer,
        private WritePlanAdvisor $writeAdvisor,
        private WebSession $session,
    ) {
    }

    #[Route('/web/connections/{id}/plan', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function plan(string $id, Request $request): JsonResponse
    {
        $input = JsonInput::fromRequest($request);
        $sql = $input->string('sql');
        $write = $input->bool('write', false);
        $uuid = Uuid::fromString($id);

        $engine = null;
        $plan = $this->targets->withSaved($uuid, function (TargetConnection $target) use ($sql, $uuid, $write, &$engine) {
            $engine = $target->engine;
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

        $findings = $this->analyzer->analyze($plan);
        if ($write && null !== $engine) {
            return new JsonResponse(PlanPresenter::presentWrite($plan, $findings, $this->writeAdvisor->advise($plan, $engine)));
        }

        return new JsonResponse(PlanPresenter::present($plan, $findings));
    }
}

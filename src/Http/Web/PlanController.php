<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Audit\AuditedQueries;
use App\Core\Driver\TargetConnection;
use App\Core\Optimizer\PlanAnalyzer;
use App\Core\Optimizer\PlanService;
use App\Core\Optimizer\StatementLimit;
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
 */
final readonly class PlanController
{
    public function __construct(
        private TargetAccess $targets,
        private AuditedQueries $queries,
        private PlanService $plans,
        private PlanAnalyzer $analyzer,
        private StatementLimit $limits,
        private WebSession $session,
    ) {
    }

    #[Route('/web/connections/{id}/plan', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function plan(string $id, Request $request): JsonResponse
    {
        $sql = JsonInput::fromRequest($request)->string('sql');
        $uuid = Uuid::fromString($id);

        $limited = false;
        $plan = $this->targets->withSaved($uuid, function (TargetConnection $target) use ($sql, $uuid, &$limited) {
            $limited = $this->limits->has($target->engine, $sql);
            $statement = $this->plans->statementFor($target->engine, $sql);
            $result = $this->queries->execute(
                $this->session->auditContext(),
                $uuid,
                $target,
                $statement,
                new QueryOptions(QueryMode::ReadOnly, (new QueryOptions())->timeoutMs, 10),
                extra: ['purpose' => 'plan'],
                recordedSql: $sql,
            );

            return $this->plans->parse($target->engine, $result);
        });

        return new JsonResponse(PlanPresenter::present($plan, $this->analyzer->analyze($plan, $limited)));
    }
}

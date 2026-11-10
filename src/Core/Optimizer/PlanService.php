<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

use App\Core\Connection\Engine;
use App\Core\Guard\Decision;
use App\Core\Guard\GuardReason;
use App\Core\Guard\StatementGuard;
use App\Core\Guard\StatementKind;
use App\Core\Optimizer\Exception\PlanNotAvailableException;
use App\Core\Optimizer\Exception\UnreadablePlanException;
use App\Core\Query\Exception\QueryRefusedException;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryResult;

/**
 * Prépare et lit un EXPLAIN. Seule une lecture simple est expliquée : le garde l'examine d'abord (une écriture, deux
 * instructions ou un SHOW ne sont pas expliqués), puis l'instruction EXPLAIN reconstruite repasse par l'exécution
 * ordinaire de l'appelant, avec ses limites et son audit. EXPLAIN seul ne lance pas la requête : le plan est une
 * estimation, pas une mesure.
 */
final readonly class PlanService
{
    public function __construct(private StatementGuard $guard)
    {
    }

    /**
     * @throws PlanNotAvailableException
     * @throws QueryRefusedException
     */
    public function statementFor(Engine $engine, string $sql): string
    {
        if (!$engine->speaksSql()) {
            throw PlanNotAvailableException::for($engine);
        }

        $verdict = $this->guard->check($sql, $engine, QueryMode::ReadOnly);
        if (Decision::Allowed !== $verdict->decision || StatementKind::Read !== $verdict->kind) {
            throw new QueryRefusedException($verdict->reason ?? GuardReason::NotReadOnly);
        }

        $statement = ltrim(rtrim($sql, " \t\r\n;"));

        return match ($engine) {
            Engine::PostgreSql => 'EXPLAIN (FORMAT JSON) ',
            Engine::ClickHouse => 'EXPLAIN json=1, indexes=1 ',
            default => 'EXPLAIN FORMAT=JSON ',
        }.$statement;
    }

    /**
     * @throws UnreadablePlanException
     */
    public function parse(Engine $engine, QueryResult $result): QueryPlan
    {
        $json = $result->rows[0][0] ?? null;
        if (!\is_string($json)) {
            throw UnreadablePlanException::create();
        }

        $parser = match ($engine) {
            Engine::PostgreSql => new PostgreSqlPlanParser(),
            Engine::ClickHouse => new ClickHousePlanParser(),
            default => new MySqlPlanParser(),
        };

        return $parser->parse($json);
    }
}

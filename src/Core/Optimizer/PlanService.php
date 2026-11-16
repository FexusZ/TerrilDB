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
 * estimation, pas une mesure ; `measuredStatementFor` est la seule exception, une lecture simple jouée pour de bon à la demande.
 *
 * Une écriture simple (INSERT, UPDATE, DELETE, REPLACE) n'est expliquée que par `statementForWrite`, appelée
 * explicitement : même EXPLAIN seul, jamais `ANALYZE`, qui jouerait l'instruction pour de bon. Aucun chemin d'écriture ne
 * construit d'ANALYZE ; un test le verrouille.
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
        $statement = $this->plainRead($engine, $sql);

        return match ($engine) {
            Engine::PostgreSql => 'EXPLAIN (FORMAT JSON) ',
            Engine::ClickHouse => 'EXPLAIN json=1, indexes=1 ',
            default => 'EXPLAIN FORMAT=JSON ',
        }.$statement;
    }

    /**
     * Le moteur sait-il MESURER (jouer la requête pour de bon) ? Pas ClickHouse : son EXPLAIN reste une estimation.
     */
    public function canMeasure(Engine $engine): bool
    {
        return $engine->speaksSql() && Engine::ClickHouse !== $engine;
    }

    /**
     * L'EXPLAIN ANALYZE d'une LECTURE simple : le moteur la JOUE (c'est la mesure), donc seulement ce que le garde classe
     * comme une lecture en lecture seule, une seule instruction (un refus d'écriture, de verrou durable, de séquence qui
     * avance… est net, sans mesure), et jamais un EXPLAIN déjà écrit. L'exécution qui suit reste en transaction lecture
     * seule annulée, avec le délai du moteur. Aucune écriture n'est mesurée, pour aucun moteur.
     *
     * @throws PlanNotAvailableException
     * @throws QueryRefusedException
     */
    public function measuredStatementFor(Engine $engine, string $sql): string
    {
        if (!$this->canMeasure($engine)) {
            throw PlanNotAvailableException::for($engine);
        }

        return match ($engine) {
            Engine::PostgreSql => 'EXPLAIN (ANALYZE, FORMAT JSON, BUFFERS) ',
            Engine::MariaDb => 'ANALYZE FORMAT=JSON ',
            default => 'EXPLAIN ANALYZE ',
        }.$this->plainRead($engine, $sql);
    }

    /**
     * @throws PlanNotAvailableException
     * @throws QueryRefusedException
     */
    private function plainRead(Engine $engine, string $sql): string
    {
        if (!$engine->speaksSql()) {
            throw PlanNotAvailableException::for($engine);
        }

        $verdict = $this->guard->check($sql, $engine, QueryMode::ReadOnly);
        if (Decision::Allowed !== $verdict->decision || StatementKind::Read !== $verdict->kind) {
            throw new QueryRefusedException($verdict->reason ?? GuardReason::NotReadOnly);
        }

        return ltrim(rtrim($sql, " \t\r\n;"));
    }

    /**
     * L'EXPLAIN (seul : il n'exécute rien) d'un INSERT, UPDATE, DELETE ou REPLACE simple. Le garde juge l'instruction
     * en lecture-écriture, comme pour une exécution : un refus ferme (plusieurs instructions, construct interdit)
     * reste un refus, mais une demande de confirmation ne gêne pas, puisque rien ne s'exécute. Ni WITH, ni CALL, ni
     * un EXPLAIN déjà écrit (surtout pas EXPLAIN ANALYZE). ClickHouse n'a pas d'EXPLAIN pour ses mutations.
     *
     * @throws PlanNotAvailableException
     * @throws QueryRefusedException
     */
    public function statementForWrite(Engine $engine, string $sql): string
    {
        if (!$engine->speaksSql() || Engine::ClickHouse === $engine) {
            throw PlanNotAvailableException::for($engine);
        }

        $verdict = $this->guard->check($sql, $engine, QueryMode::ReadWrite);
        if (Decision::Refused === $verdict->decision) {
            throw new QueryRefusedException($verdict->reason ?? GuardReason::NotReadOnly);
        }

        $statement = ltrim(rtrim($sql, " \t\r\n;"));
        if (StatementKind::Write !== $verdict->kind || 1 !== preg_match('/^(?:INSERT|UPDATE|DELETE|REPLACE)\b/i', $statement)) {
            throw new QueryRefusedException(GuardReason::NotReadOnly);
        }

        return match ($engine) {
            Engine::PostgreSql => 'EXPLAIN (FORMAT JSON) ',
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

    /**
     * Le plan MESURÉ : `parse` pour PostgreSQL et MariaDB (JSON), le texte arborescent pour MySQL. Un résultat qui n'a pas de
     * chiffres réels n'est pas un plan mesuré.
     *
     * @throws UnreadablePlanException
     */
    public function parseMeasured(Engine $engine, QueryResult $result): QueryPlan
    {
        $output = $result->rows[0][0] ?? null;
        if (!\is_string($output)) {
            throw UnreadablePlanException::create();
        }

        $parser = match ($engine) {
            Engine::PostgreSql => new PostgreSqlPlanParser(),
            Engine::MariaDb => new MariaDbAnalyzeParser(),
            Engine::MySql => new MySqlTreePlanParser(),
            default => throw PlanNotAvailableException::for($engine),
        };

        return $parser->parseMeasured($output);
    }
}

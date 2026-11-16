<?php

declare(strict_types=1);

namespace App\Driver\PostgreSql;

use App\Core\Schema\DefinerReport;
use App\Core\Schema\TableUpkeep;
use App\Core\Schema\UpkeepReport;
use App\Driver\OperationsFailure;
use App\Driver\RowReader;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;

/**
 * Entretien des tables de PostgreSQL, lu dans pg_stat_user_tables (schéma courant) : tuples vivants et morts, lignes modifiées
 * depuis la dernière analyse, dates de la dernière analyse et du dernier vacuum, manuels ou automatiques. Ce sont des estimations
 * du collecteur de statistiques, remises à zéro après un arrêt brutal : l'audit en tient compte dans ses seuils.
 */
final readonly class PostgreSqlOperations
{
    public function __construct(private Connection $connection)
    {
    }

    public function upkeep(): UpkeepReport
    {
        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT relname, n_live_tup, n_dead_tup, n_mod_since_analyze,
                        to_char(GREATEST(last_analyze, last_autoanalyze) AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS analyzed,
                        to_char(GREATEST(last_vacuum, last_autovacuum) AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS vacuumed
                   FROM pg_stat_user_tables
                  WHERE schemaname = current_schema()
                  ORDER BY relname COLLATE \"C\"
                  LIMIT 1000",
            );
        } catch (Exception $failure) {
            return UpkeepReport::unavailable(OperationsFailure::reason($failure));
        }

        $tables = [];
        foreach ($rows as $row) {
            $row = new RowReader($row);
            $tables[] = new TableUpkeep(
                $row->string('relname'),
                liveRows: $row->nullableInt('n_live_tup'),
                deadRows: $row->nullableInt('n_dead_tup'),
                modifiedSinceAnalyze: $row->nullableInt('n_mod_since_analyze'),
                lastAnalyze: $row->nullableString('analyzed'),
                lastVacuum: $row->nullableString('vacuumed'),
            );
        }

        return UpkeepReport::of($tables);
    }

    public function orphanDefiners(): DefinerReport
    {
        return DefinerReport::unavailable('not_supported');
    }
}

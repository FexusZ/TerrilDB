<?php

declare(strict_types=1);

namespace App\Driver\PostgreSql;

use App\Core\Schema\PartitionDefinition;
use App\Core\Schema\PartitioningInfo;
use App\Core\Schema\PartitionMethod;
use App\Driver\RowReader;
use Doctrine\DBAL\Connection;

/**
 * Les partitions déclaratives d'une table PostgreSQL : la clé dans pg_partitioned_table (texte de pg_get_partkeydef), les partitions
 * dans pg_inherits, leur borne par pg_get_expr. La taille d'une partition compte ses index et son stockage annexe ; ses lignes sont
 * l'estimation du moteur (reltuples, nulle tant qu'elle n'a jamais été analysée). Un sous-partitionnement de PostgreSQL est une
 * partition qui est elle-même partitionnée : on ne le suit pas ici.
 */
final readonly class PostgreSqlPartitionReader
{
    public function __construct(private Connection $connection)
    {
    }

    public function read(int $oid): ?PartitioningInfo
    {
        $key = $this->connection->fetchAssociative(
            'SELECT pt.partstrat AS strategy, pg_get_partkeydef(pt.partrelid) AS definition
               FROM pg_partitioned_table pt
              WHERE pt.partrelid = ?',
            [$oid],
        );
        if (false === $key) {
            return null;
        }
        $key = new RowReader($key);
        $method = match ($key->string('strategy')) {
            'r' => PartitionMethod::Range,
            'l' => PartitionMethod::List,
            'h' => PartitionMethod::Hash,
            default => null,
        };
        if (null === $method) {
            return null;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ch.relname AS name, pg_get_expr(ch.relpartbound, ch.oid) AS bound, ch.reltuples::bigint AS estimate,
                    pg_total_relation_size(ch.oid) AS bytes
               FROM pg_inherits i
               JOIN pg_class ch ON ch.oid = i.inhrelid
              WHERE i.inhparent = ?
              ORDER BY ch.relname COLLATE "C"',
            [$oid],
        );
        $partitions = [];
        foreach ($rows as $row) {
            $row = new RowReader($row);
            $estimate = $row->nullableInt('estimate');
            [$bound, $upper, $catchAll] = self::bound($method, $row->nullableString('bound'));
            $partitions[] = new PartitionDefinition($row->string('name'), $bound, $upper, $catchAll, null === $estimate || $estimate < 0 ? null : $estimate, $row->nullableInt('bytes'));
        }
        if ([] === $partitions) {
            return null;
        }

        return new PartitioningInfo($method, self::expression($key->string('definition')), null, null, $partitions);
    }

    /**
     * « RANGE (created_on) » -> « created_on », une expression garde sa seule paire de parenthèses : « (id * 2) ».
     */
    private static function expression(string $definition): string
    {
        $inner = 1 === preg_match('/^\w+ \((.*)\)$/s', $definition, $m) ? $m[1] : $definition;

        // Une expression seule est rendue entre deux paires de parenthèses : une suffit à la montrer.
        if (1 === preg_match('/^\(\((?:[^()]|\([^()]*\))*\)\)$/', $inner)) {
            $inner = substr($inner, 1, -1);
        }

        return '' === $inner ? $definition : $inner;
    }

    /**
     * @return array{?string, ?string, bool} borne affichable, limite haute d'une plage (littéral sans guillemets), fourre-tout
     */
    private static function bound(PartitionMethod $method, ?string $bound): array
    {
        if (null === $bound) {
            return [null, null, false];
        }
        if ('DEFAULT' === $bound) {
            return ['DEFAULT', null, true];
        }
        $display = str_starts_with($bound, 'FOR VALUES ') ? substr($bound, \strlen('FOR VALUES ')) : $bound;
        if (PartitionMethod::Range !== $method) {
            return [$display, null, false];
        }
        if (1 === preg_match('/ TO \(MAXVALUE\)$/', $display)) {
            return [$display, null, true];
        }
        $upper = null;
        if (1 === preg_match("/ TO \\('([^'\\\\]*)'\\)\$/", $display, $m) || 1 === preg_match('/ TO \((-?\d+)\)$/', $display, $m)) {
            $upper = $m[1];
        }

        return [$display, $upper, false];
    }
}

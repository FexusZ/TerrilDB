<?php

declare(strict_types=1);

namespace App\Driver\MySql;

use App\Core\Schema\PartitionDefinition;
use App\Core\Schema\PartitioningInfo;
use App\Core\Schema\PartitionMethod;
use App\Driver\RowReader;
use Doctrine\DBAL\Connection;

/**
 * Les partitions d'une table MySQL ou MariaDB, lues dans information_schema.PARTITIONS (le même catalogue pour les deux). Une table
 * sans partition y a une seule ligne au nom de partition nul. Les lignes et les tailles sont celles du moteur (estimations InnoDB).
 */
final readonly class MySqlPartitionReader
{
    public function __construct(private Connection $connection)
    {
    }

    public function read(string $table): ?PartitioningInfo
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT PARTITION_NAME AS name, SUBPARTITION_NAME AS sub_name, PARTITION_METHOD AS method, SUBPARTITION_METHOD AS sub_method,
                    PARTITION_EXPRESSION AS expression, SUBPARTITION_EXPRESSION AS sub_expression, PARTITION_DESCRIPTION AS description,
                    TABLE_ROWS AS table_rows, DATA_LENGTH AS data_bytes, INDEX_LENGTH AS index_bytes
               FROM information_schema.PARTITIONS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL
              ORDER BY PARTITION_ORDINAL_POSITION, SUBPARTITION_ORDINAL_POSITION',
            [$table],
        );
        if ([] === $rows) {
            return null;
        }

        $first = new RowReader($rows[0]);
        $method = self::method($first->string('method'));
        if (null === $method) {
            return null;
        }

        /** @var array<string, array{description: ?string, rows: ?int, bytes: ?int, subs: list<PartitionDefinition>}> $grouped */
        $grouped = [];
        foreach ($rows as $row) {
            $row = new RowReader($row);
            $name = $row->string('name');
            $measure = [$row->nullableInt('table_rows'), self::bytes($row)];
            $grouped[$name] ??= ['description' => $row->nullableString('description'), 'rows' => null, 'bytes' => null, 'subs' => []];
            $grouped[$name]['rows'] = self::add($grouped[$name]['rows'], $measure[0]);
            $grouped[$name]['bytes'] = self::add($grouped[$name]['bytes'], $measure[1]);
            $sub = $row->nullableString('sub_name');
            if (null !== $sub) {
                $grouped[$name]['subs'][] = new PartitionDefinition($sub, null, null, false, $measure[0], $measure[1]);
            }
        }

        $partitions = [];
        foreach ($grouped as $name => $partition) {
            [$bound, $upper, $catchAll] = self::bound($method, $partition['description']);
            $partitions[] = new PartitionDefinition((string) $name, $bound, $upper, $catchAll, $partition['rows'], $partition['bytes'], $partition['subs']);
        }

        $subMethod = self::method($first->nullableString('sub_method') ?? '');

        return new PartitioningInfo(
            $method,
            self::expression($first->nullableString('expression')),
            $subMethod,
            null === $subMethod ? null : self::expression($first->nullableString('sub_expression')),
            $partitions,
        );
    }

    private static function method(string $name): ?PartitionMethod
    {
        $name = strtoupper($name);

        return match (true) {
            str_contains($name, 'RANGE') => PartitionMethod::Range,
            str_contains($name, 'LIST') => PartitionMethod::List,
            str_contains($name, 'HASH') => PartitionMethod::Hash,
            str_contains($name, 'KEY') => PartitionMethod::Key,
            default => null,
        };
    }

    /**
     * KEY() sans colonne se sert de la clé primaire : le catalogue ne rend alors pas d'expression.
     */
    private static function expression(?string $expression): string
    {
        return null === $expression || '' === trim($expression) ? '(clé primaire)' : $expression;
    }

    private static function bytes(RowReader $row): ?int
    {
        return self::add($row->nullableInt('data_bytes'), $row->nullableInt('index_bytes'));
    }

    private static function add(?int $a, ?int $b): ?int
    {
        return null === $a && null === $b ? null : ($a ?? 0) + ($b ?? 0);
    }

    /**
     * @return array{?string, ?string, bool} borne affichable, limite haute d'une plage (littéral sans guillemets), fourre-tout
     */
    private static function bound(PartitionMethod $method, ?string $description): array
    {
        if (null === $description || '' === $description || (PartitionMethod::Range !== $method && PartitionMethod::List !== $method)) {
            return [null, null, false];
        }

        if (PartitionMethod::List === $method) {
            return ['IN ('.$description.')', null, 'DEFAULT' === strtoupper($description)];
        }

        if ('MAXVALUE' === strtoupper($description)) {
            return ['LESS THAN (MAXVALUE)', null, true];
        }
        $upper = 1 === preg_match("/^'([^',\\\\]*)'$/", $description, $m) ? $m[1] : (1 === preg_match('/^-?\d+$/', $description) ? $description : null);

        return ['LESS THAN ('.$description.')', $upper, false];
    }
}

<?php

declare(strict_types=1);

namespace App\Driver\MySql;

use App\Core\Connection\Engine;
use App\Core\Driver\Exception\UnsupportedOperationException;
use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\DatabaseSummary;
use App\Core\Schema\Exception\TableNotFoundException;
use App\Core\Schema\ForeignKeyDefinition;
use App\Core\Schema\IndexDefinition;
use App\Core\Schema\ReferentialAction;
use App\Core\Schema\SchemaIntrospector;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;
use App\Core\Schema\TablePage;
use App\Core\Schema\TablePageRequest;
use App\Core\Schema\TableSummary;
use App\Driver\RowReader;
use Doctrine\DBAL\Connection;

/**
 * Introspection de MySQL et MariaDB par information_schema, dans la base de la connexion. Les noms de tables
 * ne sont jamais concaténés au SQL : ils passent en paramètres liés.
 *
 * Les index fonctionnels (expressions) n'ont pas de nom de colonne dans information_schema : leurs parties
 * sont ignorées, et un index qui n'a plus aucune colonne nommée n'est pas listé.
 */
final readonly class MySqlIntrospector implements SchemaIntrospector
{
    public function __construct(private Connection $connection)
    {
    }

    private const SYSTEM_DATABASES = ['information_schema', 'mysql', 'performance_schema', 'sys'];

    public function listDatabases(): array
    {
        // information_schema.SCHEMATA ne montre que les bases sur lesquelles le compte a un droit.
        $names = $this->connection->fetchFirstColumn('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA');
        $databases = [];
        foreach ($names as $name) {
            $name = (new RowReader(['name' => $name]))->string('name');
            $databases[] = new DatabaseSummary($name, \in_array(strtolower($name), self::SYSTEM_DATABASES, true));
        }
        // À l'octet : l'ordre du moteur dépend de la collation.
        usort($databases, static fn (DatabaseSummary $a, DatabaseSummary $b): int => strcmp($a->name, $b->name));

        return $databases;
    }

    public function listSchemas(): array
    {
        throw UnsupportedOperationException::for(Engine::MySql, 'schemas');
    }

    public function listTables(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT TABLE_NAME AS name, TABLE_TYPE AS kind, TABLE_ROWS AS estimate
               FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
              ORDER BY TABLE_NAME',
        );

        $tables = [];
        foreach ($rows as $row) {
            $row = new RowReader($row);
            $kind = self::kind($row->string('kind'));
            $tables[] = new TableSummary($row->string('name'), $kind, TableKind::View === $kind ? null : $row->nullableInt('estimate'));
        }
        // L'ordre du moteur dépend de la collation (MariaDB ne classe pas « _ » comme MySQL) : on trie à l'octet.
        usort($tables, static fn (TableSummary $a, TableSummary $b): int => strcmp($a->name, $b->name));

        return $tables;
    }

    private static function count(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    public function listTablesPage(TablePageRequest $request): TablePage
    {
        $where = 'TABLE_SCHEMA = DATABASE()';
        $params = [];
        if (null !== $request->search) {
            $where .= ' AND INSTR(LOWER(TABLE_NAME), LOWER(?)) > 0';
            $params[] = $request->search;
        }
        $total = null === $request->after
            ? self::count($this->connection->fetchOne('SELECT COUNT(*) FROM information_schema.TABLES WHERE '.$where, $params))
            : null;
        if (null !== $request->after) {
            // BINARY : on compare les octets, pas selon la collation (qui ignore la casse).
            $where .= ' AND BINARY TABLE_NAME > BINARY ?';
            $params[] = $request->after;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT TABLE_NAME AS name, TABLE_TYPE AS kind, TABLE_ROWS AS estimate
               FROM information_schema.TABLES
              WHERE '.$where.'
              ORDER BY BINARY TABLE_NAME
              LIMIT '.($request->limit + 1),
            $params,
        );

        return self::page($rows, $request->limit, $total);
    }

    /**
     * @param list<array<string, mixed>> $rows une ligne de plus que la limite : elle dit seulement qu'il y a une suite
     */
    private static function page(array $rows, int $limit, ?int $total): TablePage
    {
        $more = \count($rows) > $limit;
        $tables = [];
        foreach (\array_slice($rows, 0, $limit) as $row) {
            $row = new RowReader($row);
            $kind = self::kind($row->string('kind'));
            $tables[] = new TableSummary($row->string('name'), $kind, TableKind::View === $kind ? null : $row->nullableInt('estimate'));
        }

        return new TablePage($tables, $more ? $tables[\count($tables) - 1]->name : null, $total);
    }

    public function describeTable(string $name): TableDefinition
    {
        $header = $this->connection->fetchAssociative(
            'SELECT TABLE_NAME AS name, TABLE_TYPE AS kind, TABLE_ROWS AS estimate, DATA_LENGTH AS data_bytes, INDEX_LENGTH AS index_bytes
               FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$name],
        );
        if (false === $header) {
            throw TableNotFoundException::named($name);
        }

        $header = new RowReader($header);
        // Le nom rendu par le moteur fait foi (sensibilité à la casse comprise).
        $name = $header->string('name');
        $kind = self::kind($header->string('kind'));

        return new TableDefinition(
            $name,
            $kind,
            $this->columns($name),
            $this->indexes($name),
            $this->foreignKeys($name),
            TableKind::View === $kind ? $this->viewDefinition($name) : null,
            TableKind::View === $kind ? null : $header->nullableInt('estimate'),
            TableKind::View === $kind ? null : $header->nullableInt('data_bytes'),
            TableKind::View === $kind ? null : $header->nullableInt('index_bytes'),
        );
    }

    private static function kind(string $type): TableKind
    {
        return 'VIEW' === $type ? TableKind::View : TableKind::Table;
    }

    /**
     * @return list<ColumnDefinition>
     */
    private function columns(string $table): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS col_default,
                    EXTRA AS extra, COLUMN_COMMENT AS comment
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
              ORDER BY ORDINAL_POSITION',
            [$table],
        );

        $columns = [];
        foreach ($rows as $row) {
            $row = new RowReader($row);
            $nullable = $row->bool('nullable');
            $default = $row->nullableString('col_default');
            $columns[] = new ColumnDefinition(
                $row->string('name'),
                $row->string('type'),
                $nullable,
                // MariaDB écrit le littéral NULL pour « pas de défaut » sur une colonne nullable.
                $nullable && 'NULL' === $default ? null : $default,
                str_contains($row->string('extra'), 'auto_increment'),
                $row->nullableString('comment'),
            );
        }

        return $columns;
    }

    /**
     * La place de chaque index secondaire, d'après les statistiques persistantes d'InnoDB. Lecture facultative : sans droit sur
     * `mysql.innodb_index_stats` (ou avec un autre moteur de stockage), les tailles restent inconnues.
     *
     * @return array<string, int>
     */
    private function indexSizes(string $table): array
    {
        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT index_name AS name, stat_value * @@innodb_page_size AS size
                   FROM mysql.innodb_index_stats
                  WHERE database_name = DATABASE() AND table_name = ? AND stat_name = 'size'",
                [$table],
            );
        } catch (\Throwable) {
            return [];
        }

        $sizes = [];
        foreach ($rows as $row) {
            $row = new RowReader($row);
            $size = $row->nullableInt('size');
            if (null !== $size) {
                $sizes[$row->string('name')] = $size;
            }
        }

        return $sizes;
    }

    /**
     * @return list<IndexDefinition>
     */
    private function indexes(string $table): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT INDEX_NAME AS name, NON_UNIQUE AS non_unique, COLUMN_NAME AS col
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
              ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$table],
        );

        /** @var array<string, array{unique: bool, columns: list<string>}> $grouped */
        $grouped = [];
        foreach ($rows as $row) {
            $row = new RowReader($row);
            $name = $row->string('name');
            $grouped[$name] ??= ['unique' => !$row->bool('non_unique'), 'columns' => []];
            $column = $row->nullableString('col');
            if (null !== $column) {
                $grouped[$name]['columns'][] = $column;
            }
        }

        $sizes = $this->indexSizes($table);
        $indexes = [];
        foreach ($grouped as $name => $index) {
            if ([] === $index['columns']) {
                continue;
            }
            // La clé primaire InnoDB est la table elle-même : sa place est déjà dans les données.
            $indexes[] = new IndexDefinition((string) $name, $index['columns'], $index['unique'], 'PRIMARY' === $name, 'PRIMARY' === $name ? null : ($sizes[(string) $name] ?? null));
        }
        usort($indexes, static fn (IndexDefinition $a, IndexDefinition $b): int => [!$a->primary, 0] <=> [!$b->primary, 0] ?: strcmp($a->name, $b->name));

        return $indexes;
    }

    /**
     * @return list<ForeignKeyDefinition>
     */
    private function foreignKeys(string $table): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT k.CONSTRAINT_NAME AS name, k.COLUMN_NAME AS col, k.REFERENCED_TABLE_NAME AS ref_table,
                    k.REFERENCED_COLUMN_NAME AS ref_col, r.UPDATE_RULE AS on_update, r.DELETE_RULE AS on_delete
               FROM information_schema.KEY_COLUMN_USAGE k
               JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                 ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
                AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
                AND r.TABLE_NAME = k.TABLE_NAME
              WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL
              ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION',
            [$table],
        );

        /** @var array<string, array{table: string, update: string, delete: string, columns: list<string>, referenced: list<string>}> $grouped */
        $grouped = [];
        foreach ($rows as $row) {
            $row = new RowReader($row);
            $name = $row->string('name');
            $grouped[$name] ??= [
                'table' => $row->string('ref_table'),
                'update' => $row->string('on_update'),
                'delete' => $row->string('on_delete'),
                'columns' => [],
                'referenced' => [],
            ];
            $grouped[$name]['columns'][] = $row->string('col');
            $grouped[$name]['referenced'][] = $row->string('ref_col');
        }

        $keys = [];
        foreach ($grouped as $name => $key) {
            $keys[] = new ForeignKeyDefinition(
                (string) $name,
                $key['columns'],
                $key['table'],
                $key['referenced'],
                ReferentialAction::fromSql($key['update']),
                ReferentialAction::fromSql($key['delete']),
            );
        }
        usort($keys, static fn (ForeignKeyDefinition $a, ForeignKeyDefinition $b): int => strcmp($a->name, $b->name));

        return $keys;
    }

    private function viewDefinition(string $view): ?string
    {
        $definition = $this->connection->fetchOne(
            'SELECT VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$view],
        );

        return \is_string($definition) && '' !== $definition ? $definition : null;
    }
}

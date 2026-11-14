<?php

declare(strict_types=1);

namespace App\Driver\ClickHouse;

use App\Core\Connection\Engine;
use App\Core\Driver\Exception\UnsupportedOperationException;
use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\DatabaseSummary;
use App\Core\Schema\Exception\TableNotFoundException;
use App\Core\Schema\IndexDefinition;
use App\Core\Schema\IndexUsageReport;
use App\Core\Schema\SchemaIntrospector;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;
use App\Core\Schema\TablePage;
use App\Core\Schema\TablePageRequest;
use App\Core\Schema\TableSummary;
use App\Driver\RowReader;

/**
 * Introspection de ClickHouse par ses tables système (system.tables, system.columns, system.data_skipping_indices),
 * dans la base de la connexion. Les noms passent en paramètres liés.
 *
 * ClickHouse n'a ni clé étrangère ni contrainte d'unicité, et sa « clé primaire » n'identifie pas une ligne : la clé
 * de tri est donc rendue comme un index non unique nommé « ORDER BY » (et « PRIMARY KEY » quand elle en diffère),
 * jamais comme une clé primaire au sens relationnel. Les index de saut de données suivent. Le nombre de lignes est
 * celui que le moteur connaît (exact pour MergeTree, absent pour une vue).
 */
final readonly class ClickHouseIntrospector implements SchemaIntrospector
{
    public const SORTING_KEY_INDEX = 'ORDER BY';
    public const PRIMARY_KEY_INDEX = 'PRIMARY KEY';

    public function __construct(private ClickHouseHttp $http, private string $database)
    {
    }

    private const SYSTEM_DATABASES = ['system', 'information_schema'];

    public function listDatabases(): array
    {
        $databases = [];
        foreach ($this->http->query('SELECT name FROM system.databases ORDER BY name')->records() as $record) {
            $name = (new RowReader($record))->string('name');
            $databases[] = new DatabaseSummary($name, \in_array(strtolower($name), self::SYSTEM_DATABASES, true));
        }
        usort($databases, static fn (DatabaseSummary $a, DatabaseSummary $b): int => strcmp($a->name, $b->name));

        return $databases;
    }

    public function indexUsage(): IndexUsageReport
    {
        return IndexUsageReport::unavailable('not_supported');
    }

    public function listSchemas(): array
    {
        throw UnsupportedOperationException::for(Engine::ClickHouse, 'schemas');
    }

    public function listTables(): array
    {
        $records = $this->http->query(
            "SELECT name, engine, total_rows FROM system.tables
              WHERE database = {db:String} AND NOT is_temporary AND name NOT LIKE '.inner%'
              ORDER BY name",
            params: ['db' => $this->database],
        )->records();

        $tables = [];
        foreach ($records as $record) {
            $row = new RowReader($record);
            $kind = self::kind($row->string('engine'));
            $tables[] = new TableSummary($row->string('name'), $kind, TableKind::Table === $kind ? $row->nullableInt('total_rows') : null);
        }
        // À l'octet, pour un ordre indépendant de la collation.
        usort($tables, static fn (TableSummary $a, TableSummary $b): int => strcmp($a->name, $b->name));

        return $tables;
    }

    public function listTablesPage(TablePageRequest $request): TablePage
    {
        $where = "database = {db:String} AND NOT is_temporary AND name NOT LIKE '.inner%'";
        $params = ['db' => $this->database];
        if (null !== $request->search) {
            $where .= ' AND positionCaseInsensitive(name, {q:String}) > 0';
            $params['q'] = $request->search;
        }
        $total = null === $request->after
            ? (int) (new RowReader($this->http->query('SELECT count() AS n FROM system.tables WHERE '.$where, params: $params)->records()[0] ?? ['n' => 0]))->nullableInt('n')
            : null;
        if (null !== $request->after) {
            // Les chaînes de ClickHouse se comparent octet par octet : l'ordre est celui du reste de TerrilDB.
            $where .= ' AND name > {after:String}';
            $params['after'] = $request->after;
        }
        $params['limit'] = (string) ($request->limit + 1);

        $records = $this->http->query(
            'SELECT name, engine, total_rows FROM system.tables WHERE '.$where.' ORDER BY name LIMIT {limit:UInt32}',
            params: $params,
        )->records();

        $more = \count($records) > $request->limit;
        $tables = [];
        foreach (\array_slice($records, 0, $request->limit) as $record) {
            $row = new RowReader($record);
            $kind = self::kind($row->string('engine'));
            $tables[] = new TableSummary($row->string('name'), $kind, TableKind::Table === $kind ? $row->nullableInt('total_rows') : null);
        }

        return new TablePage($tables, $more ? $tables[\count($tables) - 1]->name : null, $total);
    }

    public function describeTable(string $name): TableDefinition
    {
        $params = ['db' => $this->database, 'name' => $name];
        $header = $this->http->query(
            'SELECT engine, as_select, primary_key, sorting_key, total_rows FROM system.tables
              WHERE database = {db:String} AND name = {name:String} AND NOT is_temporary',
            params: $params,
        )->records()[0] ?? throw TableNotFoundException::named($name);
        $header = new RowReader($header);
        $kind = self::kind($header->string('engine'));
        [$dataBytes, $indexBytes, $keyBytes, $skipBytes] = TableKind::Table === $kind ? $this->sizes($params) : [null, null, null, []];

        $columns = [];
        foreach ($this->http->query(
            'SELECT name, type, default_kind, default_expression, comment FROM system.columns
              WHERE database = {db:String} AND table = {name:String} ORDER BY position',
            params: $params,
        )->records() as $record) {
            $row = new RowReader($record);
            $type = $row->string('type');
            $default = $row->nullableString('default_expression');
            $kindOfDefault = $row->string('default_kind');
            $columns[] = new ColumnDefinition(
                $row->string('name'),
                $type,
                str_starts_with($type, 'Nullable(') || str_starts_with($type, 'LowCardinality(Nullable('),
                null === $default ? null : ('' === $kindOfDefault || 'DEFAULT' === $kindOfDefault ? $default : $kindOfDefault.' '.$default),
                false,
                $row->nullableString('comment'),
            );
        }

        return new TableDefinition(
            $name,
            $kind,
            $columns,
            TableKind::Table === $kind ? $this->indexes($header, $params, $keyBytes, $skipBytes) : [],
            [],
            TableKind::Table === $kind ? null : $header->nullableString('as_select'),
            TableKind::Table === $kind ? $header->nullableInt('total_rows') : null,
            $dataBytes,
            $indexBytes,
        );
    }

    /**
     * Place sur disque (octets compressés) des parties actives : au total, pour la clé de tri, et pour chaque index de saut. Les
     * index = clé de tri + index de saut ; les données = le reste.
     *
     * @param array<string, string> $params
     *
     * @return array{?int, ?int, ?int, array<string, int>}
     */
    private function sizes(array $params): array
    {
        $parts = $this->http->query(
            'SELECT sum(bytes_on_disk) AS disk, sum(primary_key_size) AS pk FROM system.parts
              WHERE database = {db:String} AND table = {name:String} AND active',
            params: $params,
        )->records()[0] ?? null;
        $skips = [];
        foreach ($this->http->query(
            'SELECT name, data_compressed_bytes AS size FROM system.data_skipping_indices WHERE database = {db:String} AND table = {name:String}',
            params: $params,
        )->records() as $record) {
            $row = new RowReader($record);
            $skips[$row->string('name')] = $row->int('size');
        }
        if (null === $parts) {
            return [null, null, null, $skips];
        }
        $parts = new RowReader($parts);
        $disk = $parts->nullableInt('disk');
        $key = $parts->nullableInt('pk');
        $indexes = ($key ?? 0) + array_sum($skips);

        return [null === $disk ? null : max(0, $disk - $indexes), null === $disk ? null : $indexes, $key, $skips];
    }

    /**
     * @param array<string, string> $params
     * @param array<string, int>    $skipBytes
     *
     * @return list<IndexDefinition>
     */
    private function indexes(RowReader $header, array $params, ?int $keyBytes, array $skipBytes): array
    {
        $indexes = [];
        $sorting = KeyExpression::split($header->string('sorting_key'));
        $primary = KeyExpression::split($header->string('primary_key'));
        if ([] !== $sorting) {
            $indexes[] = new IndexDefinition(self::SORTING_KEY_INDEX, $sorting, false, false, $keyBytes);
        }
        if ([] !== $primary && $primary !== $sorting) {
            $indexes[] = new IndexDefinition(self::PRIMARY_KEY_INDEX, $primary, false, false);
        }

        foreach ($this->http->query(
            'SELECT name, expr FROM system.data_skipping_indices WHERE database = {db:String} AND table = {name:String} ORDER BY name',
            params: $params,
        )->records() as $record) {
            $row = new RowReader($record);
            $columns = KeyExpression::split($row->string('expr'));
            if ([] !== $columns) {
                $indexes[] = new IndexDefinition($row->string('name'), $columns, false, false, $skipBytes[$row->string('name')] ?? null);
            }
        }

        return $indexes;
    }

    private static function kind(string $engine): TableKind
    {
        return match ($engine) {
            'View', 'LiveView', 'WindowView' => TableKind::View,
            'MaterializedView' => TableKind::MaterializedView,
            default => TableKind::Table,
        };
    }
}

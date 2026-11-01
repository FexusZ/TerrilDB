<?php

declare(strict_types=1);

namespace App\Driver\PostgreSql;

use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\Exception\InvalidSchemaException;
use App\Core\Schema\Exception\TableNotFoundException;
use App\Core\Schema\ForeignKeyDefinition;
use App\Core\Schema\IndexDefinition;
use App\Core\Schema\ReferentialAction;
use App\Core\Schema\SchemaIntrospector;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;
use App\Core\Schema\TableSummary;
use App\Driver\RowReader;
use Doctrine\DBAL\Connection;

/**
 * Introspection de PostgreSQL par les catalogues pg_catalog, dans le schéma courant de la connexion.
 * Les noms de tables passent en paramètres liés ; ensuite on interroge par identifiant d'objet (oid).
 *
 * Le type d'une colonne est celui que PostgreSQL écrit lui-même (format_type). Une colonne d'identité ou
 * adossée à une séquence compte comme auto-incrémentée.
 */
final readonly class PostgreSqlIntrospector implements SchemaIntrospector
{
    private const RELATIONS = "c.relkind IN ('r', 'p', 'v', 'm') AND n.nspname = current_schema()";

    public function __construct(private Connection $connection)
    {
    }

    public function listTables(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.relname AS name, c.relkind AS kind, c.reltuples::bigint AS estimate
               FROM pg_class c
               JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE '.self::RELATIONS.'
              ORDER BY c.relname',
        );

        $tables = [];
        foreach ($rows as $row) {
            $row = new RowReader($row);
            $kind = self::kind($row->string('kind'));
            $tables[] = new TableSummary($row->string('name'), $kind, self::estimate($kind, $row));
        }
        // On trie à l'octet pour obtenir le même ordre quelle que soit la collation de la base.
        usort($tables, static fn (TableSummary $a, TableSummary $b): int => strcmp($a->name, $b->name));

        return $tables;
    }

    public function describeTable(string $name): TableDefinition
    {
        $header = $this->connection->fetchAssociative(
            'SELECT c.oid::bigint AS oid, c.relkind AS kind, c.reltuples::bigint AS estimate
               FROM pg_class c
               JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE '.self::RELATIONS.' AND c.relname = ?',
            [$name],
        );
        if (false === $header) {
            throw TableNotFoundException::named($name);
        }

        $header = new RowReader($header);
        $oid = $header->int('oid');
        $kind = self::kind($header->string('kind'));

        return new TableDefinition(
            $name,
            $kind,
            $this->columns($oid),
            $this->indexes($oid),
            $this->foreignKeys($oid),
            TableKind::Table === $kind ? null : $this->viewDefinition($oid),
            self::estimate($kind, $header),
        );
    }

    private static function kind(string $relkind): TableKind
    {
        return match ($relkind) {
            'r', 'p' => TableKind::Table,
            'v' => TableKind::View,
            'm' => TableKind::MaterializedView,
            default => throw new InvalidSchemaException('Unknown relation kind.'),
        };
    }

    /**
     * reltuples vaut -1 tant que la table n'a jamais été analysée, et ne veut rien dire pour une vue.
     */
    private static function estimate(TableKind $kind, RowReader $row): ?int
    {
        if (TableKind::View === $kind) {
            return null;
        }
        $estimate = $row->nullableInt('estimate');

        return null === $estimate || $estimate < 0 ? null : $estimate;
    }

    /**
     * @return list<ColumnDefinition>
     */
    private function columns(int $oid): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT a.attname AS name,
                    format_type(a.atttypid, a.atttypmod) AS type,
                    NOT a.attnotnull AS nullable,
                    pg_get_expr(d.adbin, d.adrelid) AS col_default,
                    (a.attidentity <> '' OR COALESCE(pg_get_expr(d.adbin, d.adrelid), '') LIKE 'nextval(%') AS auto_increment,
                    col_description(a.attrelid, a.attnum) AS comment
               FROM pg_attribute a
               LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
              WHERE a.attrelid = ? AND a.attnum > 0 AND NOT a.attisdropped
              ORDER BY a.attnum",
            [$oid],
        );

        $columns = [];
        foreach ($rows as $row) {
            $row = new RowReader($row);
            $columns[] = new ColumnDefinition(
                $row->string('name'),
                $row->string('type'),
                $row->bool('nullable'),
                $row->nullableString('col_default'),
                $row->bool('auto_increment'),
                $row->nullableString('comment'),
            );
        }

        return $columns;
    }

    /**
     * @return list<IndexDefinition>
     */
    private function indexes(int $oid): array
    {
        // indnkeyatts exclut les colonnes INCLUDE ; pg_get_indexdef(…, n, true) rend la colonne ou le texte de l'expression.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ic.relname AS name, i.indisunique AS is_unique, i.indisprimary AS is_primary,
                    pg_get_indexdef(i.indexrelid, k.n, true) AS col
               FROM pg_index i
               JOIN pg_class ic ON ic.oid = i.indexrelid
              CROSS JOIN LATERAL generate_series(1, i.indnkeyatts) AS k(n)
              WHERE i.indrelid = ?
              ORDER BY ic.relname, k.n',
            [$oid],
        );

        /** @var array<string, array{unique: bool, primary: bool, columns: list<string>}> $grouped */
        $grouped = [];
        foreach ($rows as $row) {
            $row = new RowReader($row);
            $name = $row->string('name');
            $grouped[$name] ??= ['unique' => $row->bool('is_unique'), 'primary' => $row->bool('is_primary'), 'columns' => []];
            $grouped[$name]['columns'][] = $row->string('col');
        }

        $indexes = [];
        foreach ($grouped as $name => $index) {
            $indexes[] = new IndexDefinition((string) $name, $index['columns'], $index['unique'], $index['primary']);
        }
        usort($indexes, static fn (IndexDefinition $a, IndexDefinition $b): int => [!$a->primary, 0] <=> [!$b->primary, 0] ?: strcmp($a->name, $b->name));

        return $indexes;
    }

    /**
     * @return list<ForeignKeyDefinition>
     */
    private function foreignKeys(int $oid): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT con.conname AS name, a.attname AS col, ra.attname AS ref_col, rc.relname AS ref_table,
                    con.confupdtype AS on_update, con.confdeltype AS on_delete
               FROM pg_constraint con
              CROSS JOIN LATERAL unnest(con.conkey, con.confkey) WITH ORDINALITY AS k(attnum, refnum, ord)
               JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = k.attnum
               JOIN pg_attribute ra ON ra.attrelid = con.confrelid AND ra.attnum = k.refnum
               JOIN pg_class rc ON rc.oid = con.confrelid
              WHERE con.contype = 'f' AND con.conrelid = ?
              ORDER BY con.conname, k.ord",
            [$oid],
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
                self::action($key['update']),
                self::action($key['delete']),
            );
        }
        usort($keys, static fn (ForeignKeyDefinition $a, ForeignKeyDefinition $b): int => strcmp($a->name, $b->name));

        return $keys;
    }

    private static function action(string $code): ReferentialAction
    {
        return match ($code) {
            'a' => ReferentialAction::NoAction,
            'r' => ReferentialAction::Restrict,
            'c' => ReferentialAction::Cascade,
            'n' => ReferentialAction::SetNull,
            'd' => ReferentialAction::SetDefault,
            default => throw new InvalidSchemaException('Unknown referential action code.'),
        };
    }

    private function viewDefinition(int $oid): ?string
    {
        $definition = $this->connection->fetchOne('SELECT pg_get_viewdef(?::oid, true)', [$oid]);

        return \is_string($definition) && '' !== $definition ? $definition : null;
    }
}

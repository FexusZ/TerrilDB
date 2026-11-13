<?php

declare(strict_types=1);

namespace App\Core\Schema\Change\Dialect;

use App\Core\Connection\Engine;
use App\Core\Schema\Change\ColumnDraft;
use App\Core\Schema\Change\Exception\InvalidSchemaChangeException;
use App\Core\Schema\Change\ForeignKeyDraft;
use App\Core\Schema\Change\IndexDraft;
use App\Core\Schema\Change\Operation\AddColumn;
use App\Core\Schema\Change\Operation\DropColumn;
use App\Core\Schema\Change\Operation\DropForeignKey;
use App\Core\Schema\Change\Operation\DropIndex;
use App\Core\Schema\Change\Operation\DropTable;
use App\Core\Schema\Change\Operation\ModifyColumn;
use App\Core\Schema\Change\Operation\RenameColumn;
use App\Core\Schema\Change\Operation\RenameIndex;
use App\Core\Schema\Change\Operation\RenameTable;
use App\Core\Schema\Change\TableDraft;
use App\Core\Schema\IndexDefinition;
use App\Core\Schema\TableDefinition;

/**
 * ClickHouse : pas de transaction, pas de clé primaire relationnelle ni de clé étrangère ni de colonne auto-incrémentée. La
 * nullabilité s'écrit dans le type (Nullable(T)) ; la clé de tri (« ORDER BY ») se lit mais ne se change pas ; les index sont des
 * index de saut (minmax, bloom_filter, set(n)) sans unicité ni renommage, à matérialiser pour les données déjà là.
 */
final class ClickHouseDdlDialect extends AbstractDdlDialect
{
    /** Les « index » que l'introspection invente pour la clé de tri : informatifs, jamais modifiables. */
    private const array SORT_KEYS = ['ORDER BY', 'PRIMARY KEY'];

    public function transactional(): bool
    {
        return false;
    }

    public function prepare(TableDefinition $current, TableDraft $draft): TableDraft
    {
        if ([] !== $draft->foreignKeys) {
            throw new InvalidSchemaChangeException('ClickHouse has no foreign keys.');
        }
        if (null !== $draft->primaryKey()) {
            throw new InvalidSchemaChangeException('ClickHouse has no primary key to edit: the sort key is fixed when the table is created.');
        }
        foreach ($current->indexes as $index) {
            if (!\in_array($index->name, self::SORT_KEYS, true)) {
                continue;
            }
            $kept = false;
            foreach ($draft->indexes as $wanted) {
                if ($wanted->originalName === $index->name && $wanted->columns === $index->columns) {
                    $kept = true;
                }
            }
            if (!$kept) {
                throw new InvalidSchemaChangeException('The sort key of a ClickHouse table cannot be changed.');
            }
        }

        return $draft->withColumns(array_map(
            fn (ColumnDraft $column): ColumnDraft => new ColumnDraft($column->originalName, $column->name, $this->typeWithNullability($column->validatedType(), $column->nullable), $column->nullable, $column->default, $column->autoIncrement),
            $draft->columns,
        ));
    }

    private function typeWithNullability(string $type, bool $nullable): string
    {
        $wrapped = str_starts_with($type, 'Nullable(') || str_starts_with($type, 'LowCardinality(Nullable(');
        if ($nullable === $wrapped) {
            return $type;
        }
        if ($nullable) {
            return str_starts_with($type, 'LowCardinality(') ? 'LowCardinality(Nullable('.substr($type, 15, -1).'))' : 'Nullable('.$type.')';
        }

        return str_starts_with($type, 'Nullable(') ? substr($type, 9, -1) : 'LowCardinality('.substr($type, 24, -2).')';
    }

    protected function quote(string $name): string
    {
        return $this->quoteName(Engine::ClickHouse, $name);
    }

    protected function stringLiteral(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }

    protected function dropForeignKey(string $table, DropForeignKey $operation): array
    {
        throw new InvalidSchemaChangeException('ClickHouse has no foreign keys.');
    }

    protected function dropIndex(string $table, DropIndex $operation): array
    {
        return [$this->statement(\sprintf('ALTER TABLE %s DROP INDEX %s', $this->quote($table), $this->quote($operation->name)), 'drop_index', $operation->name, true)];
    }

    protected function dropPrimaryKey(string $table, IndexDefinition $old): array
    {
        throw new InvalidSchemaChangeException('ClickHouse has no primary key to edit.');
    }

    protected function dropColumn(string $table, DropColumn $operation): array
    {
        return [$this->statement(\sprintf('ALTER TABLE %s DROP COLUMN %s', $this->quote($table), $this->quote($operation->name)), 'drop_column', $operation->name, true)];
    }

    protected function renameColumn(string $table, RenameColumn $operation): array
    {
        return [$this->statement(\sprintf('ALTER TABLE %s RENAME COLUMN %s TO %s', $this->quote($table), $this->quote($operation->from), $this->quote($operation->to)), 'rename_column', $operation->from)];
    }

    protected function modifyColumn(string $table, ModifyColumn $operation): array
    {
        $column = $operation->after;
        if ($column->autoIncrement) {
            throw new InvalidSchemaChangeException('ClickHouse has no auto-increment column.');
        }
        $sql = \sprintf('ALTER TABLE %s MODIFY COLUMN %s %s', $this->quote($table), $this->quote($column->name), $column->validatedType());
        $default = $column->defaultValue();
        if (null !== $default) {
            $sql .= ' DEFAULT '.$this->defaultSql($default);
        }
        $statements = [$this->statement($sql, 'modify_column', $column->name, $operation->typeChanged)];
        if ($operation->defaultChanged && null === $default) {
            $statements[] = $this->statement(\sprintf('ALTER TABLE %s MODIFY COLUMN %s REMOVE DEFAULT', $this->quote($table), $this->quote($column->name)), 'modify_column', $column->name);
        }

        return $statements;
    }

    protected function addColumn(string $table, AddColumn $operation): array
    {
        $column = $operation->column;
        if ($column->autoIncrement) {
            throw new InvalidSchemaChangeException('ClickHouse has no auto-increment column.');
        }
        $sql = \sprintf('ALTER TABLE %s ADD COLUMN %s %s', $this->quote($table), $this->quote($column->name), $column->validatedType());
        $default = $column->defaultValue();
        if (null !== $default) {
            $sql .= ' DEFAULT '.$this->defaultSql($default);
        }

        return [$this->statement($sql, 'add_column', $column->name)];
    }

    protected function addPrimaryKey(string $table, IndexDraft $index): array
    {
        throw new InvalidSchemaChangeException('ClickHouse has no primary key to edit.');
    }

    protected function addIndex(string $table, IndexDraft $index): array
    {
        if ($index->unique) {
            throw new InvalidSchemaChangeException('A ClickHouse index cannot be unique.');
        }
        $expression = 1 === \count($index->columns) ? $this->quote($index->columns[0]) : '('.$this->columnList($index->columns).')';

        return [
            $this->statement(
                \sprintf('ALTER TABLE %s ADD INDEX %s %s TYPE %s GRANULARITY %d', $this->quote($table), $this->quote($index->name), $expression, $index->kind ?? 'minmax', $index->granularity ?? 1),
                'add_index',
                $index->name,
            ),
            // Sans cela l'index ne couvre que les données écrites après sa création.
            $this->statement(\sprintf('ALTER TABLE %s MATERIALIZE INDEX %s', $this->quote($table), $this->quote($index->name)), 'materialize_index', $index->name),
        ];
    }

    protected function renameIndex(string $table, RenameIndex $operation): array
    {
        throw new InvalidSchemaChangeException('A ClickHouse index cannot be renamed: delete it and create it again.');
    }

    protected function addForeignKey(string $table, ForeignKeyDraft $foreignKey): array
    {
        throw new InvalidSchemaChangeException('ClickHouse has no foreign keys.');
    }

    protected function renameTable(RenameTable $operation): array
    {
        return [$this->statement(\sprintf('RENAME TABLE %s TO %s', $this->quote($operation->from), $this->quote($operation->to)), 'rename_table', $operation->from)];
    }

    protected function dropTable(DropTable $operation): array
    {
        return [$this->statement(\sprintf('DROP TABLE %s', $this->quote($operation->name)), 'drop_table', $operation->name, true)];
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Schema\Change\Dialect;

use App\Core\Schema\Change\ColumnDraft;
use App\Core\Schema\Change\DdlStatement;
use App\Core\Schema\Change\DefaultKind;
use App\Core\Schema\Change\DefaultValue;
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
use App\Core\Schema\Change\Operation\SetPrimaryKey;
use App\Core\Schema\IndexDefinition;
use App\Core\Schema\ReferentialAction;

/**
 * MySQL et MariaDB (même syntaxe pour tout ce qui est ici). Chaque DDL est validé tout seul par le moteur : pas de tout-ou-rien.
 * Modifier une colonne en redit toute la définition (MODIFY COLUMN) : le commentaire est conservé, mais le jeu de caractères et
 * la collation d'une colonne texte reviennent à ceux de la table.
 */
final class MySqlDdlDialect extends AbstractDdlDialect
{
    public function transactional(): bool
    {
        return false;
    }

    public function statements(string $table, array $operations): array
    {
        return parent::statements($table, $this->mergeAutoIncrementWithItsKey($table, $operations));
    }

    /**
     * MySQL refuse une colonne AUTO_INCREMENT qui n'est pas déjà une clé : la colonne nouvelle et sa clé primaire partent dans
     * UN seul ALTER TABLE.
     *
     * @param list<object> $operations
     *
     * @return list<object>
     */
    private function mergeAutoIncrementWithItsKey(string $table, array $operations): array
    {
        $primary = null;
        foreach ($operations as $operation) {
            if ($operation instanceof SetPrimaryKey && null === $operation->old && null !== $operation->new) {
                $primary = $operation;
            }
        }
        if (null === $primary || null === $primary->new) {
            return $operations;
        }
        foreach ($operations as $index => $operation) {
            if ($operation instanceof AddColumn && $operation->column->autoIncrement && [$operation->column->name] === $primary->new->columns) {
                $merged = new MergedAddColumnAndKey($operation, $primary->new);
                unset($operations[$index], $operations[array_search($primary, $operations, true)]);
                $operations[] = $merged;

                return array_values($operations);
            }
        }

        return $operations;
    }

    protected function quote(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    protected function stringLiteral(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "''"], $value)."'";
    }

    protected function defaultSql(DefaultValue $default): string
    {
        if (DefaultKind::Expression !== $default->kind) {
            return parent::defaultSql($default);
        }

        return match (true) {
            'now()' === $default->value, 'localtimestamp' === $default->value, str_starts_with($default->value, 'current_timestamp') => 'now()' === $default->value || 'localtimestamp' === $default->value ? 'CURRENT_TIMESTAMP' : strtoupper($default->value),
            'uuid()' === $default->value => '(uuid())',
            'true' === $default->value => 'TRUE',
            'false' === $default->value => 'FALSE',
            'current_date' === $default->value => '(CURRENT_DATE)',
            'current_time' === $default->value => '(CURRENT_TIME)',
            default => throw new InvalidSchemaChangeException('This default value is not available on MySQL and MariaDB.'),
        };
    }

    protected function dropForeignKey(string $table, DropForeignKey $operation): array
    {
        return [$this->statement(\sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $this->quote($table), $this->quote($operation->name)), 'drop_foreign_key', $operation->name, true)];
    }

    protected function dropIndex(string $table, DropIndex $operation): array
    {
        return [$this->statement(\sprintf('DROP INDEX %s ON %s', $this->quote($operation->name), $this->quote($table)), 'drop_index', $operation->name, true)];
    }

    protected function dropPrimaryKey(string $table, IndexDefinition $old): array
    {
        return [$this->statement(\sprintf('ALTER TABLE %s DROP PRIMARY KEY', $this->quote($table)), 'drop_primary_key', implode(', ', $old->columns), true)];
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
        $definition = $this->columnDefinition($operation->after);
        // MODIFY COLUMN redit toute la définition : ce que le modèle ne dit pas doit être redit, sinon il disparaît.
        if ($operation->before->invisible) {
            $definition .= ' INVISIBLE';
        }
        if (null !== $operation->before->comment && '' !== $operation->before->comment) {
            $definition .= ' COMMENT '.$this->stringLiteral($operation->before->comment);
        }

        return [$this->statement(\sprintf('ALTER TABLE %s MODIFY COLUMN %s', $this->quote($table), $definition), 'modify_column', $operation->after->name, $operation->typeChanged)];
    }

    protected function addColumn(string $table, AddColumn $operation): array
    {
        return [$this->statement(\sprintf('ALTER TABLE %s ADD COLUMN %s', $this->quote($table), $this->columnDefinition($operation->column)), 'add_column', $operation->column->name)];
    }

    /**
     * @return list<DdlStatement>
     */
    protected function addColumnWithItsKey(string $table, MergedAddColumnAndKey $merged): array
    {
        return [$this->statement(
            \sprintf('ALTER TABLE %s ADD COLUMN %s, ADD PRIMARY KEY (%s)', $this->quote($table), $this->columnDefinition($merged->column->column), $this->columnList($merged->key->columns)),
            'add_column',
            $merged->column->column->name,
        )];
    }

    protected function addPrimaryKey(string $table, IndexDraft $index): array
    {
        return [$this->statement(\sprintf('ALTER TABLE %s ADD PRIMARY KEY (%s)', $this->quote($table), $this->columnList($index->columns)), 'add_primary_key', implode(', ', $index->columns))];
    }

    protected function addIndex(string $table, IndexDraft $index): array
    {
        return [$this->statement(
            \sprintf('CREATE %sINDEX %s ON %s (%s)', $index->unique ? 'UNIQUE ' : '', $this->quote($index->name), $this->quote($table), $this->columnList($index->columns)),
            'add_index',
            $index->name,
        )];
    }

    protected function renameIndex(string $table, RenameIndex $operation): array
    {
        return [$this->statement(\sprintf('ALTER TABLE %s RENAME INDEX %s TO %s', $this->quote($table), $this->quote($operation->from), $this->quote($operation->to)), 'rename_index', $operation->from)];
    }

    protected function addForeignKey(string $table, ForeignKeyDraft $foreignKey): array
    {
        if (ReferentialAction::SetDefault === $foreignKey->onUpdate || ReferentialAction::SetDefault === $foreignKey->onDelete) {
            throw new InvalidSchemaChangeException('MySQL and MariaDB do not support SET DEFAULT on a foreign key.');
        }

        return [$this->statement(
            \sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON UPDATE %s ON DELETE %s',
                $this->quote($table),
                $this->quote($foreignKey->name),
                $this->columnList($foreignKey->columns),
                $this->quote($foreignKey->referencedTable),
                $this->columnList($foreignKey->referencedColumns),
                $this->referentialAction($foreignKey->onUpdate),
                $this->referentialAction($foreignKey->onDelete),
            ),
            'add_foreign_key',
            $foreignKey->name,
        )];
    }

    protected function renameTable(RenameTable $operation): array
    {
        return [$this->statement(\sprintf('RENAME TABLE %s TO %s', $this->quote($operation->from), $this->quote($operation->to)), 'rename_table', $operation->from)];
    }

    protected function dropTable(DropTable $operation): array
    {
        return [$this->statement(\sprintf('DROP TABLE %s', $this->quote($operation->name)), 'drop_table', $operation->name, true)];
    }

    private function columnDefinition(ColumnDraft $column): string
    {
        $sql = \sprintf('%s %s %s', $this->quote($column->name), $column->validatedType(), $column->nullable ? 'NULL' : 'NOT NULL');
        $default = $column->defaultValue();
        if (null !== $default) {
            $sql .= ' DEFAULT '.$this->defaultSql($default);
        }
        if ($column->autoIncrement) {
            $sql .= ' AUTO_INCREMENT';
        }

        return $sql;
    }
}

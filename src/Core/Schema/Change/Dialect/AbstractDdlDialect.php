<?php

declare(strict_types=1);

namespace App\Core\Schema\Change\Dialect;

use App\Core\Schema\Change\DdlStatement;
use App\Core\Schema\Change\DefaultKind;
use App\Core\Schema\Change\DefaultValue;
use App\Core\Schema\Change\ForeignKeyDraft;
use App\Core\Schema\Change\IndexDraft;
use App\Core\Schema\Change\Operation\AddColumn;
use App\Core\Schema\Change\Operation\AddForeignKey;
use App\Core\Schema\Change\Operation\AddIndex;
use App\Core\Schema\Change\Operation\DropColumn;
use App\Core\Schema\Change\Operation\DropForeignKey;
use App\Core\Schema\Change\Operation\DropIndex;
use App\Core\Schema\Change\Operation\DropTable;
use App\Core\Schema\Change\Operation\ModifyColumn;
use App\Core\Schema\Change\Operation\RenameColumn;
use App\Core\Schema\Change\Operation\RenameIndex;
use App\Core\Schema\Change\Operation\RenameTable;
use App\Core\Schema\Change\Operation\SetPrimaryKey;
use App\Core\Schema\Change\TableDraft;
use App\Core\Schema\IndexDefinition;
use App\Core\Schema\ReferentialAction;
use App\Core\Schema\TableDefinition;

/**
 * Ce qui est commun aux dialectes : l'ordre des instructions et leur description. Chaque moteur écrit ses propres phrases.
 *
 * Ordre : on retire d'abord ce qui dépend d'autre chose (clés étrangères, index, clé primaire), puis les colonnes (retirer,
 * renommer, modifier, ajouter), puis on remet clé primaire, index et clés étrangères, et on renomme la table en dernier : toutes
 * les instructions parlent ainsi de la table sous son nom d'avant.
 */
abstract class AbstractDdlDialect implements DdlDialect
{
    public function prepare(TableDefinition $current, TableDraft $draft): TableDraft
    {
        return $draft;
    }

    public function statements(string $table, array $operations): array
    {
        $phases = array_fill(0, 12, []);
        foreach ($operations as $operation) {
            match (true) {
                $operation instanceof DropForeignKey => $phases[0][] = $this->dropForeignKey($table, $operation),
                $operation instanceof DropIndex => $phases[1][] = $this->dropIndex($table, $operation),
                $operation instanceof SetPrimaryKey => $this->primaryKey($table, $operation, $phases),
                $operation instanceof DropColumn => $phases[3][] = $this->dropColumn($table, $operation),
                $operation instanceof RenameColumn => $phases[4][] = $this->renameColumn($table, $operation),
                $operation instanceof ModifyColumn => $phases[5][] = $this->modifyColumn($table, $operation),
                $operation instanceof AddColumn => $phases[6][] = $this->addColumn($table, $operation),
                $operation instanceof AddIndex => $phases[8][] = $this->addIndex($table, $operation->index),
                $operation instanceof RenameIndex => $phases[8][] = $this->renameIndex($table, $operation),
                $operation instanceof AddForeignKey => $phases[9][] = $this->addForeignKey($table, $operation->foreignKey),
                $operation instanceof MergedAddColumnAndKey => $phases[6][] = $this->addColumnWithItsKey($table, $operation),
                $operation instanceof RenameTable => $phases[10][] = $this->renameTable($operation),
                $operation instanceof DropTable => $phases[11][] = $this->dropTable($operation),
                default => throw new \LogicException('Unknown schema operation.'),
            };
        }

        $out = [];
        foreach ($phases as $phase) {
            foreach ($phase as $statements) {
                foreach ($statements as $statement) {
                    $out[] = $statement;
                }
            }
        }

        return $out;
    }

    /**
     * @param array<int, list<list<DdlStatement>>> $phases
     */
    private function primaryKey(string $table, SetPrimaryKey $operation, array &$phases): void
    {
        if (null !== $operation->old) {
            $phases[2][] = $this->dropPrimaryKey($table, $operation->old);
        }
        if (null !== $operation->new) {
            $phases[7][] = $this->addPrimaryKey($table, $operation->new);
        }
    }

    abstract protected function quote(string $name): string;

    abstract protected function stringLiteral(string $value): string;

    /**
     * @return list<DdlStatement>
     */
    abstract protected function dropForeignKey(string $table, DropForeignKey $operation): array;

    /**
     * @return list<DdlStatement>
     */
    abstract protected function dropIndex(string $table, DropIndex $operation): array;

    /**
     * @return list<DdlStatement>
     */
    abstract protected function dropPrimaryKey(string $table, IndexDefinition $old): array;

    /**
     * @return list<DdlStatement>
     */
    abstract protected function dropColumn(string $table, DropColumn $operation): array;

    /**
     * @return list<DdlStatement>
     */
    abstract protected function renameColumn(string $table, RenameColumn $operation): array;

    /**
     * @return list<DdlStatement>
     */
    abstract protected function modifyColumn(string $table, ModifyColumn $operation): array;

    /**
     * @return list<DdlStatement>
     */
    abstract protected function addColumn(string $table, AddColumn $operation): array;

    /**
     * Seul MySQL a besoin de cette forme fusionnée ; les autres dialectes ne la reçoivent jamais.
     *
     * @return list<DdlStatement>
     */
    protected function addColumnWithItsKey(string $table, MergedAddColumnAndKey $merged): array
    {
        throw new \LogicException('This dialect does not merge a column and its key.');
    }

    /**
     * @return list<DdlStatement>
     */
    abstract protected function addPrimaryKey(string $table, IndexDraft $index): array;

    /**
     * @return list<DdlStatement>
     */
    abstract protected function addIndex(string $table, IndexDraft $index): array;

    /**
     * @return list<DdlStatement>
     */
    abstract protected function renameIndex(string $table, RenameIndex $operation): array;

    /**
     * @return list<DdlStatement>
     */
    abstract protected function addForeignKey(string $table, ForeignKeyDraft $foreignKey): array;

    /**
     * @return list<DdlStatement>
     */
    abstract protected function renameTable(RenameTable $operation): array;

    /**
     * @return list<DdlStatement>
     */
    abstract protected function dropTable(DropTable $operation): array;

    /**
     * @param list<string> $columns
     */
    protected function columnList(array $columns): string
    {
        return implode(', ', array_map($this->quote(...), $columns));
    }

    protected function defaultSql(DefaultValue $default): string
    {
        return match ($default->kind) {
            DefaultKind::Null => 'NULL',
            DefaultKind::Number, DefaultKind::Expression => $default->value,
            DefaultKind::Literal => $this->stringLiteral($default->value),
        };
    }

    protected function referentialAction(ReferentialAction $action): string
    {
        return match ($action) {
            ReferentialAction::NoAction => 'NO ACTION',
            ReferentialAction::Restrict => 'RESTRICT',
            ReferentialAction::Cascade => 'CASCADE',
            ReferentialAction::SetNull => 'SET NULL',
            ReferentialAction::SetDefault => 'SET DEFAULT',
        };
    }

    protected function statement(string $sql, string $operation, string $subject, bool $destructive = false): DdlStatement
    {
        return new DdlStatement($sql, $operation, $subject, $destructive);
    }
}

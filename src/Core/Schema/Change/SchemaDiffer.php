<?php

declare(strict_types=1);

namespace App\Core\Schema\Change;

use App\Core\Schema\Change\Exception\InvalidSchemaChangeException;
use App\Core\Schema\Change\Operation\AddColumn;
use App\Core\Schema\Change\Operation\AddForeignKey;
use App\Core\Schema\Change\Operation\AddIndex;
use App\Core\Schema\Change\Operation\DropColumn;
use App\Core\Schema\Change\Operation\DropForeignKey;
use App\Core\Schema\Change\Operation\DropIndex;
use App\Core\Schema\Change\Operation\ModifyColumn;
use App\Core\Schema\Change\Operation\RenameColumn;
use App\Core\Schema\Change\Operation\RenameIndex;
use App\Core\Schema\Change\Operation\RenameTable;
use App\Core\Schema\Change\Operation\SetPrimaryKey;
use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\ForeignKeyDefinition;
use App\Core\Schema\IndexDefinition;
use App\Core\Schema\TableDefinition;

/**
 * Compare la table réelle à la table voulue et rend les opérations qui mènent de l'une à l'autre. Le renommage d'une colonne
 * (ou d'un index) se reconnaît à `originalName` : il ne détruit rien, et les index qui la portent la suivent. L'ordre dans lequel
 * les instructions sont émises est l'affaire du dialecte, pas d'ici.
 */
final class SchemaDiffer
{
    /**
     * @return list<object>
     */
    public function diff(TableDefinition $current, TableDraft $draft): array
    {
        $operations = [];
        if ($current->name !== $draft->name) {
            $operations[] = new RenameTable($current->name, $draft->name);
        }

        $renames = [];
        $kept = [];
        foreach ($draft->columns as $column) {
            if (null === $column->originalName) {
                $operations[] = new AddColumn($column);
                continue;
            }
            $before = $current->column($column->originalName)
                ?? throw new InvalidSchemaChangeException('A column refers to an original column that no longer exists: the table changed since it was loaded.');
            $kept[$before->name] = true;
            if ($before->name !== $column->name) {
                $renames[$before->name] = $column->name;
                $operations[] = new RenameColumn($before->name, $column->name);
            }
            $modify = $this->modification($before, $column);
            if (null !== $modify) {
                $operations[] = $modify;
            }
        }
        foreach ($current->columns as $column) {
            if (!isset($kept[$column->name])) {
                $operations[] = new DropColumn($column->name);
            }
        }

        $currentPrimary = $current->primaryKey();
        $draftPrimary = $draft->primaryKey();
        if ($this->primaryChanged($currentPrimary, $draftPrimary, $renames)) {
            $operations[] = new SetPrimaryKey($currentPrimary, $draftPrimary);
        }

        array_push($operations, ...$this->indexOperations($current, $draft, $renames));
        array_push($operations, ...$this->foreignKeyOperations($current, $draft, $renames));

        return $operations;
    }

    private function modification(ColumnDefinition $before, ColumnDraft $after): ?ModifyColumn
    {
        $type = self::normalizedType($before->type) !== self::normalizedType($after->validatedType());
        $nullability = $before->nullable !== $after->nullable;
        $default = !self::sameDefault($before->default, $after->default);
        $auto = $before->autoIncrement !== $after->autoIncrement;
        if (!$type && !$nullability && !$default && !$auto) {
            return null;
        }

        return new ModifyColumn($before, $after, $type, $nullability, $default, $auto);
    }

    /**
     * @param array<string, string> $renames
     */
    private function primaryChanged(?IndexDefinition $current, ?IndexDraft $draft, array $renames): bool
    {
        if (null === $current || null === $draft) {
            return $current !== $draft;
        }

        return self::mapped($current->columns, $renames) !== $draft->columns;
    }

    /**
     * @param array<string, string> $renames
     *
     * @return list<object>
     */
    private function indexOperations(TableDefinition $current, TableDraft $draft, array $renames): array
    {
        $drafts = [];
        foreach ($draft->indexes as $index) {
            if (!$index->primary && null !== $index->originalName) {
                $drafts[$index->originalName] = $index;
            }
        }

        $operations = [];
        $untouched = [];
        foreach ($current->indexes as $index) {
            if ($index->primary) {
                continue;
            }
            $wanted = $drafts[$index->name] ?? null;
            if (null === $wanted || self::mapped($index->columns, $renames) !== $wanted->columns || $index->unique !== $wanted->unique) {
                $operations[] = new DropIndex($index->name, $index);
                continue;
            }
            $untouched[$index->name] = $wanted;
        }
        foreach ($draft->indexes as $index) {
            if ($index->primary) {
                continue;
            }
            if (null !== $index->originalName && isset($untouched[$index->originalName])) {
                if ($index->originalName !== $index->name) {
                    $operations[] = new RenameIndex($index->originalName, $index->name);
                }
                continue;
            }
            $operations[] = new AddIndex($index);
        }

        return $operations;
    }

    /**
     * @param array<string, string> $renames
     *
     * @return list<object>
     */
    private function foreignKeyOperations(TableDefinition $current, TableDraft $draft, array $renames): array
    {
        $drafts = [];
        foreach ($draft->foreignKeys as $foreignKey) {
            if (null !== $foreignKey->originalName) {
                $drafts[$foreignKey->originalName] = $foreignKey;
            }
        }

        $operations = [];
        $untouched = [];
        foreach ($current->foreignKeys as $foreignKey) {
            $wanted = $drafts[$foreignKey->name] ?? null;
            if (null === $wanted || !$this->sameForeignKey($foreignKey, $wanted, $renames)) {
                $operations[] = new DropForeignKey($foreignKey->name);
                continue;
            }
            $untouched[$foreignKey->name] = true;
        }
        foreach ($draft->foreignKeys as $foreignKey) {
            if (null !== $foreignKey->originalName && isset($untouched[$foreignKey->originalName])) {
                if ($foreignKey->originalName !== $foreignKey->name) {
                    // Renommer une contrainte n'existe pas partout : on la recrée sous son nouveau nom.
                    $operations[] = new DropForeignKey($foreignKey->originalName);
                    $operations[] = new AddForeignKey($foreignKey);
                }
                continue;
            }
            $operations[] = new AddForeignKey($foreignKey);
        }

        return $operations;
    }

    /**
     * @param array<string, string> $renames
     */
    private function sameForeignKey(ForeignKeyDefinition $current, ForeignKeyDraft $draft, array $renames): bool
    {
        return self::mapped($current->columns, $renames) === $draft->columns
            && $current->referencedTable === $draft->referencedTable
            && $current->referencedColumns === $draft->referencedColumns
            && $current->onUpdate === $draft->onUpdate
            && $current->onDelete === $draft->onDelete;
    }

    /**
     * @param list<string>          $columns
     * @param array<string, string> $renames
     *
     * @return list<string>
     */
    private static function mapped(array $columns, array $renames): array
    {
        return array_map(static fn (string $column): string => $renames[$column] ?? $column, $columns);
    }

    /** Casse, espaces et « (10,2) » / « (10, 2) » ne font pas deux types. */
    public static function normalizedType(string $type): string
    {
        $type = strtolower(trim((string) preg_replace('/\s+/', ' ', $type)));

        return (string) preg_replace(['/\(\s+/', '/\s+\)/', '/\s*,\s*/'], ['(', ')', ','], $type);
    }

    private static function sameDefault(?string $current, ?string $draft): bool
    {
        try {
            $a = DefaultValue::parse($current ?? '');
            $b = DefaultValue::parse($draft ?? '');
        } catch (InvalidSchemaChangeException) {
            return ($current ?? '') === ($draft ?? '');
        }
        // « DEFAULT NULL » et pas de valeur par défaut, c'est la même colonne.
        $a = DefaultKind::Null === $a?->kind ? null : $a;
        $b = DefaultKind::Null === $b?->kind ? null : $b;

        return $a?->kind === $b?->kind && $a?->value === $b?->value;
    }
}

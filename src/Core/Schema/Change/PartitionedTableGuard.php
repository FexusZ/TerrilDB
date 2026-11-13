<?php

declare(strict_types=1);

namespace App\Core\Schema\Change;

use App\Core\Connection\Engine;
use App\Core\Schema\Change\Exception\UnsupportedPartitionChangeException;
use App\Core\Schema\Change\Operation\AddForeignKey;
use App\Core\Schema\Change\Operation\DropColumn;
use App\Core\Schema\Change\Operation\ModifyColumn;
use App\Core\Schema\Change\Operation\RenameColumn;
use App\Core\Schema\PartitionMethod;
use App\Core\Schema\PartitioningInfo;
use App\Core\Schema\TableDefinition;

/**
 * Refus, avant tout envoi, de ce qu'on ne sait pas faire sans casser le partitionnement d'une table : retirer, renommer ou changer le
 * type d'une colonne qui sert au découpage, garder une clé primaire ou unique qui ne contient pas TOUTES les colonnes de partitionnement
 * (MySQL / MariaDB : erreurs 1503 et 1526 ; PostgreSQL : même règle), ajouter une clé étrangère sur une table partitionnée MySQL /
 * MariaDB (InnoDB ne la permet pas). Les colonnes de partitionnement se lisent par leur NOM dans l'expression : un nom de fonction
 * identique à celui d'une colonne la ferait compter (on refuse alors par excès de prudence, jamais l'inverse). L'édition des
 * partitions elles-mêmes n'existe pas.
 */
final class PartitionedTableGuard
{
    /**
     * @param list<object> $operations
     *
     * @throws UnsupportedPartitionChangeException
     */
    public static function check(Engine $engine, TableDefinition $current, TableDraft $draft, array $operations): void
    {
        $partitioning = $current->partitioning;
        if (null === $partitioning) {
            return;
        }
        $columns = self::partitionColumns($current, $partitioning);

        foreach ($operations as $operation) {
            $touched = match (true) {
                $operation instanceof DropColumn => $operation->name,
                $operation instanceof RenameColumn => $operation->from,
                $operation instanceof ModifyColumn && $operation->typeChanged => $operation->before->name,
                default => null,
            };
            if (null !== $touched && isset($columns[strtolower($touched)])) {
                throw new UnsupportedPartitionChangeException('partition_column', $touched);
            }
            if ($operation instanceof AddForeignKey && \in_array($engine, [Engine::MySql, Engine::MariaDb], true)) {
                throw new UnsupportedPartitionChangeException('partition_foreign_key', $operation->foreignKey->name);
            }
        }

        foreach ($draft->indexes as $index) {
            if (!$index->unique && !$index->primary) {
                continue;
            }
            $has = array_flip(array_map(strtolower(...), $index->columns));
            foreach ($columns as $column => $_) {
                if (!isset($has[$column])) {
                    throw new UnsupportedPartitionChangeException('partition_unique_key', $index->name);
                }
            }
        }
    }

    /**
     * @return array<string, true> les noms (en minuscules) des colonnes de la table qui servent au découpage ou au sous-découpage
     */
    private static function partitionColumns(TableDefinition $table, PartitioningInfo $partitioning): array
    {
        $found = [];
        $expressions = array_filter([$partitioning->expression, $partitioning->subExpression], static fn (?string $e): bool => null !== $e);
        foreach ($expressions as $expression) {
            if (PartitioningInfo::PRIMARY_KEY === $expression) {
                foreach ($table->primaryKey()->columns ?? [] as $column) {
                    $found[strtolower($column)] = true;
                }
                continue;
            }
            $tokens = array_flip(array_map(strtolower(...), (array) preg_split('/[^\p{L}\p{N}_$]+/u', $expression, -1, \PREG_SPLIT_NO_EMPTY)));
            foreach ($table->columns as $column) {
                if (isset($tokens[strtolower($column->name)])) {
                    $found[strtolower($column->name)] = true;
                }
            }
        }

        return $found;
    }
}

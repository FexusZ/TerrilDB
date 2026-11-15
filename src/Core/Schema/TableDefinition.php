<?php

declare(strict_types=1);

namespace App\Core\Schema;

use App\Core\Schema\Exception\InvalidSchemaException;

/**
 * Le détail d'une table ou d'une vue : colonnes dans l'ordre du moteur, index, clés étrangères sortantes.
 * Seule une vue porte une définition (le SELECT qui la compose). `dataBytes` et `indexBytes` : place sur disque des données et
 * de tous les index, quand le moteur la donne ; ce sont des mesures qui bougent, hors de l'empreinte de structure.
 * `storageEngine` (MySQL et MariaDB : InnoDB, MyISAM…) et `transactional` (ce moteur sait-il annuler ? nul si inconnu), `triggerCount`
 * (déclencheurs de la table, nul si le moteur n'en a pas ou ne le dit pas) : des informations, hors de l'empreinte elles aussi.
 * `versioned` (MariaDB, WITH SYSTEM VERSIONING) : la table garde l'historique de ses lignes ; l'éditeur ne retire jamais ce versionnement ni
 * ses colonnes de période, et supprimer la table détruit l'historique. Hors de l'empreinte : une table qui le devient entre-temps est
 * relue (donc refusée comme telle) à l'application.
 */
final readonly class TableDefinition
{
    /**
     * @param list<ColumnDefinition>     $columns
     * @param list<IndexDefinition>      $indexes
     * @param list<ForeignKeyDefinition> $foreignKeys
     */
    public function __construct(
        public string $name,
        public TableKind $kind,
        public array $columns,
        public array $indexes,
        public array $foreignKeys,
        public ?string $viewDefinition,
        public ?int $estimatedRows,
        public ?int $dataBytes = null,
        public ?int $indexBytes = null,
        public ?string $storageEngine = null,
        public ?bool $transactional = null,
        public ?int $triggerCount = null,
        public bool $versioned = false,
    ) {
        if ('' === $name || [] === $columns) {
            throw new InvalidSchemaException('A table needs a name and at least one column.');
        }
        if (\count(array_unique(array_map(static fn (ColumnDefinition $c): string => $c->name, $columns))) !== \count($columns)) {
            throw new InvalidSchemaException('A table cannot have two columns with the same name.');
        }
        if (null !== $viewDefinition && TableKind::Table === $kind) {
            throw new InvalidSchemaException('Only a view has a definition.');
        }
        if (null !== $estimatedRows && $estimatedRows < 0) {
            throw new InvalidSchemaException('The estimated row count cannot be negative.');
        }
    }

    public function column(string $name): ?ColumnDefinition
    {
        foreach ($this->columns as $column) {
            if ($column->name === $name) {
                return $column;
            }
        }

        return null;
    }

    public function primaryKey(): ?IndexDefinition
    {
        foreach ($this->indexes as $index) {
            if ($index->primary) {
                return $index;
            }
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Schema;

use App\Core\Schema\Exception\InvalidSchemaException;

/**
 * Une partition. `bound` : sa borne ou sa liste de valeurs telle que le moteur l'écrit (affichage seulement, jamais exécuté) ;
 * `upperBound` : pour un découpage par plage, la limite haute EXCLUE sous forme de littéral sans guillemets (nulle pour une
 * partition fourre-tout) ; `catchAll` : MAXVALUE ou DEFAULT. `estimatedRows` et `dataBytes` : mesures du moteur quand il les donne,
 * qui bougent sans que la structure change. `subpartitions` : le sous-découpage de MySQL / MariaDB (vide sinon).
 */
final readonly class PartitionDefinition
{
    /**
     * @param list<PartitionDefinition> $subpartitions
     */
    public function __construct(
        public string $name,
        public ?string $bound,
        public ?string $upperBound,
        public bool $catchAll,
        public ?int $estimatedRows,
        public ?int $dataBytes,
        public array $subpartitions = [],
    ) {
        if ('' === $name) {
            throw new InvalidSchemaException('A partition needs a name.');
        }
        if ((null !== $estimatedRows && $estimatedRows < 0) || (null !== $dataBytes && $dataBytes < 0)) {
            throw new InvalidSchemaException('A partition measure cannot be negative.');
        }
    }
}

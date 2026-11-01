<?php

declare(strict_types=1);

namespace App\Core\KeyValue;

use App\Core\KeyValue\Exception\InvalidKeyException;

/**
 * Le contenu d'une clé, ramené à un tableau (colonnes + lignes) quel que soit son type : une chaîne est une ligne, un
 * hash des paires champ/valeur, une liste des éléments, etc. `size` est la longueur ou la cardinalité connue du
 * serveur ; `truncated` dit qu'il y a plus que les lignes rendues.
 */
final readonly class KeyDetail
{
    /**
     * @param list<string>                           $columns
     * @param list<list<bool|float|int|string|null>> $rows
     */
    public function __construct(
        public string $name,
        public KeyType $type,
        public ?int $ttlSeconds,
        public ?int $size,
        public array $columns,
        public array $rows,
        public bool $truncated,
    ) {
        foreach ($rows as $row) {
            if (\count($row) !== \count($columns)) {
                throw new InvalidKeyException('Each row needs one value per column.');
            }
        }
    }
}

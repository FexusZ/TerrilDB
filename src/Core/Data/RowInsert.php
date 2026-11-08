<?php

declare(strict_types=1);

namespace App\Core\Data;

/**
 * Une ligne à ajouter : les colonnes absentes prennent leur valeur par défaut, une valeur nulle écrit NULL.
 */
final readonly class RowInsert
{
    /**
     * @param array<string, bool|float|int|string|null> $values
     */
    public function __construct(public array $values)
    {
    }
}

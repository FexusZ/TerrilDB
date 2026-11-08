<?php

declare(strict_types=1);

namespace App\Core\Data;

/**
 * Une ligne à supprimer, donnée par ses valeurs d'origine.
 */
final readonly class RowDelete
{
    /**
     * @param array<string, bool|float|int|string|null> $original
     */
    public function __construct(public array $original)
    {
    }
}

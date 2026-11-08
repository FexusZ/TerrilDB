<?php

declare(strict_types=1);

namespace App\Core\Data;

/**
 * Une ligne à modifier : ses valeurs d'origine telles que le client les a lues (elles servent à la retrouver et à vérifier
 * qu'elle n'a pas bougé) et les nouvelles valeurs des seules colonnes changées.
 */
final readonly class RowUpdate
{
    /**
     * @param array<string, bool|float|int|string|null> $original
     * @param array<string, bool|float|int|string|null> $changes
     */
    public function __construct(public array $original, public array $changes)
    {
    }
}

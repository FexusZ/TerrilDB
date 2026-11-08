<?php

declare(strict_types=1);

namespace App\Core\Data;

/**
 * Ce que le client veut changer dans les lignes d'une table : jamais du SQL, seulement des lignes.
 */
final readonly class DataDraft
{
    /**
     * @param list<RowUpdate> $updates
     * @param list<RowInsert> $inserts
     * @param list<RowDelete> $deletes
     */
    public function __construct(public array $updates, public array $inserts, public array $deletes)
    {
    }
}

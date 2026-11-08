<?php

declare(strict_types=1);

namespace App\Http\Json;

use App\Core\Data\DataDraft;
use App\Core\Data\RowDelete;
use App\Core\Data\RowInsert;
use App\Core\Data\RowUpdate;

/**
 * Lit les lignes envoyées par l'interface : `updates` (`original`, `changes`), `inserts` (`values`), `deletes` (`original`).
 * Seules des valeurs simples passent ; ce qui est valide ou non (colonnes, clé) est vérifié par le cœur.
 */
final class DataDraftInput
{
    public static function from(JsonInput $input): DataDraft
    {
        return new DataDraft(
            array_map(static fn (JsonInput $row): RowUpdate => new RowUpdate($row->scalars('original'), $row->scalars('changes')), $input->objects('updates')),
            array_map(static fn (JsonInput $row): RowInsert => new RowInsert($row->scalars('values')), $input->objects('inserts')),
            array_map(static fn (JsonInput $row): RowDelete => new RowDelete($row->scalars('original')), $input->objects('deletes')),
        );
    }
}

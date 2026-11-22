<?php

declare(strict_types=1);

namespace App\Http\Json;

use App\Core\Diagnostics\Vitess\StreamHit;

/**
 * Vitess : les flux VReplication qu'un changement de structure ne suivra pas. Des noms de la cible rendus comme des données.
 */
final class StreamPresenter
{
    /**
     * @param list<StreamHit> $hits
     *
     * @return list<array{workflow: string, table: string, targets: list<string>}>
     */
    public static function hits(array $hits): array
    {
        return array_map(static fn (StreamHit $hit): array => ['workflow' => $hit->workflow, 'table' => $hit->table, 'targets' => $hit->targets], $hits);
    }
}

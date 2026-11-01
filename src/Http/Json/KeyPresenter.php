<?php

declare(strict_types=1);

namespace App\Http\Json;

use App\Core\KeyValue\KeyDetail;
use App\Core\KeyValue\KeyEntry;

/**
 * Forme JSON des clés d'une base clé-valeur, partagée par l'interface web et (plus tard) l'API.
 */
final class KeyPresenter
{
    /**
     * @return array{name: string, type: string, ttl: int|null}
     */
    public static function entry(KeyEntry $entry): array
    {
        return ['name' => $entry->name, 'type' => $entry->type->value, 'ttl' => $entry->ttlSeconds];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(KeyDetail $detail): array
    {
        return [
            'name' => $detail->name,
            'type' => $detail->type->value,
            'ttl' => $detail->ttlSeconds,
            'size' => $detail->size,
            'columns' => $detail->columns,
            'rows' => $detail->rows,
            'truncated' => $detail->truncated,
        ];
    }
}

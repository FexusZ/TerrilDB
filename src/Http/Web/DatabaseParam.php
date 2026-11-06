<?php

declare(strict_types=1);

namespace App\Http\Web;

use Symfony\Component\HttpFoundation\Request;

/**
 * La base visée (et le schéma, voir {@see self::schemaFromQuery}), quand une route la laisse choisir (`?database=`) : absente ou vide, c'est celle de la connexion.
 */
final class DatabaseParam
{
    public static function fromQuery(Request $request): ?string
    {
        $value = $request->query->get('database');

        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * Le schéma visé (`?schema=`, PostgreSQL) : absent ou vide, c'est le schéma courant de la connexion.
     */
    public static function schemaFromQuery(Request $request): ?string
    {
        $value = $request->query->get('schema');

        return \is_string($value) && '' !== $value ? $value : null;
    }
}

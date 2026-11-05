<?php

declare(strict_types=1);

namespace App\Http\Web;

use Symfony\Component\HttpFoundation\Request;

/**
 * La base visée, quand une route la laisse choisir (`?database=`) : absente ou vide, c'est celle de la connexion.
 */
final class DatabaseParam
{
    public static function fromQuery(Request $request): ?string
    {
        $value = $request->query->get('database');

        return \is_string($value) && '' !== $value ? $value : null;
    }
}

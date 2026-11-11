<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * Le texte d'une requête d'une AUTRE session peut citer des valeurs d'un autre utilisateur : il est toujours raccourci et
 * débarrassé des octets invalides avant d'entrer dans un modèle de diagnostic, quel que soit le moteur.
 */
final class QueryText
{
    public const MAX_LENGTH = 200;

    public static function shorten(?string $query): ?string
    {
        if (null === $query) {
            return null;
        }
        $query = mb_scrub($query, 'UTF-8');
        if ('' === trim($query)) {
            return null;
        }

        return mb_strlen($query) > self::MAX_LENGTH ? mb_substr($query, 0, self::MAX_LENGTH - 1).'…' : $query;
    }
}

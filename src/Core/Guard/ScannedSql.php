<?php

declare(strict_types=1);

namespace App\Core\Guard;

/**
 * Résultat de l'analyse. `complete` est faux quand une chaîne ou un commentaire n'est jamais refermé : on ne
 * sait alors plus ce que le moteur lirait, et le filtre doit refuser.
 */
final readonly class ScannedSql
{
    /**
     * @param list<ScannedStatement> $statements
     * @param list<int>              $separators                   positions (octets) des points-virgules qui séparent des instructions
     * @param bool                   $separatorInExecutableComment un point-virgule se trouve dans un commentaire que MySQL exécute
     */
    public function __construct(public array $statements, public bool $complete, public array $separators = [], public bool $separatorInExecutableComment = false)
    {
    }
}

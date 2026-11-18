<?php

declare(strict_types=1);

namespace App\Core\Schema\Change\Algorithm;

/**
 * Comment le moteur exécutera probablement une instruction. `instant` : métadonnées seulement. `inplace` : sans copie de la table.
 * `copy` : copie complète de la table (MySQL, MariaDB). `rewrite` : réécriture complète de la table (PostgreSQL). `unknown` :
 * aucune règle vérifiée contre les vrais moteurs ne couvre ce cas, rien n'est affirmé.
 */
enum AlterAlgorithm: string
{
    case Instant = 'instant';
    case Inplace = 'inplace';
    case Copy = 'copy';
    case Rewrite = 'rewrite';
    case Unknown = 'unknown';

    /** Ce qui recopie toute la table. */
    public function copiesWholeTable(): bool
    {
        return self::Copy === $this || self::Rewrite === $this;
    }

    /** Du moins coûteux au plus coûteux, pour retenir le pire de plusieurs changements d'une même instruction. */
    public function cost(): int
    {
        return match ($this) {
            self::Instant => 0,
            self::Inplace => 1,
            self::Copy, self::Rewrite => 2,
            self::Unknown => 3,
        };
    }
}

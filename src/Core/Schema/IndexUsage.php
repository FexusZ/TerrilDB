<?php

declare(strict_types=1);

namespace App\Core\Schema;

/**
 * Le nombre de lectures que le moteur a comptées pour un index depuis le début de sa collecte (démarrage du serveur ou dernière
 * remise à zéro des statistiques).
 */
final readonly class IndexUsage
{
    public function __construct(
        public string $table,
        public string $index,
        public int $reads,
    ) {
    }
}

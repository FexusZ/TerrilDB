<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Health;

/**
 * Un relevé : les compteurs et jauges du moteur à un instant. Les valeurs restent du texte brut tel que le moteur les rend
 * (le calcul décide ce qui est numérique).
 */
final readonly class HealthSample
{
    /**
     * @param float                 $takenAt  horloge murale en secondes avec fraction (microtime)
     * @param array<string, string> $counters
     * @param int|null              $uptime   secondes depuis le démarrage du serveur : s'il diminue, le serveur a redémarré
     * @param string|null           $epoch    repère de remise à zéro des statistiques (PostgreSQL `stats_reset`) : s'il change, les compteurs ont été remis à zéro
     */
    public function __construct(
        public float $takenAt,
        public array $counters,
        public ?int $uptime = null,
        public ?string $epoch = null,
    ) {
    }
}

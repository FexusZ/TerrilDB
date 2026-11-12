<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Health;

use App\Core\Diagnostics\DiagnosticStatus;

/**
 * Le résultat d'une mesure : indicateurs sur l'intervalle, ou l'état qui dit pourquoi il n'y en a pas (source illisible, mesure
 * écartée). Rien n'est conservé : pas de série temporelle.
 */
final readonly class HealthReport
{
    /**
     * @param list<HealthIndicator> $indicators
     * @param string|null           $discarded  `counters_reset` ou `invalid_interval` quand la mesure est écartée
     */
    public function __construct(
        public DiagnosticStatus $status,
        public ?float $firstSampleAt,
        public ?float $secondSampleAt,
        public ?float $seconds,
        public array $indicators,
        public ?string $discarded,
    ) {
    }

    public static function unreadable(DiagnosticStatus $status): self
    {
        return new self($status, null, null, null, [], null);
    }
}

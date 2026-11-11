<?php

declare(strict_types=1);

namespace App\Core\Schema;

/**
 * L'usage des index lu au catalogue du moteur, ou la raison pour laquelle il ne l'est pas (`reason` : code stable, jamais le
 * message du moteur). `observedSeconds` est la durée de la collecte : les compteurs repartent de zéro au redémarrage, donc sans
 * elle un index « jamais lu » ne veut rien dire.
 */
final readonly class IndexUsageReport
{
    /**
     * @param list<IndexUsage> $usages
     */
    private function __construct(
        public bool $available,
        public ?string $reason,
        public int $observedSeconds,
        public array $usages,
    ) {
    }

    /**
     * @param list<IndexUsage> $usages
     */
    public static function available(int $observedSeconds, array $usages): self
    {
        return new self(true, null, max(0, $observedSeconds), $usages);
    }

    public static function unavailable(string $reason): self
    {
        return new self(false, $reason, 0, []);
    }
}

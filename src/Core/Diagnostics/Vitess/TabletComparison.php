<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Vitess;

/**
 * Les tablets d'un keyspace côte à côte : ce qu'on a lu de chacun, et les variables modifiées dont la valeur diffère de l'un à l'autre.
 */
final readonly class TabletComparison
{
    /**
     * @param list<TabletSnapshot>           $snapshots
     * @param list<TabletVariableDifference> $differences
     * @param int                            $masked      variables masquées (secrets) écartées de la comparaison, jamais montrées
     */
    public function __construct(
        public array $snapshots,
        public array $differences,
        public int $masked,
        /** Vrai si des tablets (ou des variables) au-delà de la limite n'ont pas été lus. */
        public bool $truncated = false,
    ) {
    }
}

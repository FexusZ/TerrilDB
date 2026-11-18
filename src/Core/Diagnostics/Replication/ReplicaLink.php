<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Replication;

/**
 * Un réplica vu depuis sa source (`pg_stat_replication`, lignes `slaveN` de Redis). Ni adresse, ni nom de machine, ni compte :
 * un simple rang. Les retards sont en secondes, `behindBytes` en octets (null : le moteur ne le dit pas).
 */
final readonly class ReplicaLink
{
    public function __construct(
        public int $rank,
        public ?string $state,
        /** Le réplica reçoit en continu (PostgreSQL `streaming`, Redis `online`), pas en rattrapage ni en reconnexion. */
        public bool $connected,
        /** `sync`, `async`, `potential`, `quorum` (PostgreSQL). */
        public ?string $syncState,
        public ?float $writeLagSeconds,
        public ?float $flushLagSeconds,
        public ?float $replayLagSeconds,
        public ?int $behindBytes,
    ) {
    }

    public function worstLagSeconds(): ?float
    {
        $lags = array_filter([$this->writeLagSeconds, $this->flushLagSeconds, $this->replayLagSeconds], static fn (?float $l): bool => null !== $l);

        return [] === $lags ? null : max($lags);
    }
}

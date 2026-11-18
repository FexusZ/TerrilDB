<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Replication;

/**
 * Les réglages du serveur qui conditionnent une réplication saine. Null : le moteur ne le dit pas (ou n'a pas la notion).
 */
final readonly class ReplicationSettings
{
    public function __construct(
        public ?bool $readOnly,
        public ?bool $superReadOnly,
        public ?string $binlogFormat,
        /** Identifiants de transaction globaux (GTID) utilisés. */
        public ?bool $gtid,
    ) {
    }

    /**
     * Un réplica doit refuser les écritures, y compris des comptes très privilégiés quand le moteur le sait (super_read_only).
     * Null : on ne le sait pas.
     */
    public function writesRefused(): ?bool
    {
        return $this->superReadOnly ?? $this->readOnly;
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Replication;

/**
 * Un slot de réplication de PostgreSQL. Un slot inactif ou en retard retient du WAL sur le disque du primaire : `retainedBytes`
 * est l'écart entre la position courante du serveur et le `restart_lsn` du slot (null : le slot ne retient rien encore).
 */
final readonly class ReplicationSlot
{
    public function __construct(
        public string $name,
        /** `physical` ou `logical`. */
        public string $type,
        public bool $active,
        /** Un slot temporaire disparaît avec sa session : être inactif n'a alors pas de sens. */
        public bool $temporary,
        public ?int $retainedBytes,
        /** `reserved`, `extended`, `unreserved` ou `lost` (null : rien n'est retenu). */
        public ?string $walStatus,
        /** Octets de WAL qui peuvent encore s'écrire avant que le slot soit perdu (null : pas de plafond). */
        public ?int $safeWalSize,
    ) {
    }
}

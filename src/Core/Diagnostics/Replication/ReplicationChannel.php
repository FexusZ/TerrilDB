<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Replication;

/**
 * Une réplication entrante d'un réplica : un canal (MySQL), une connexion nommée (MariaDB) ou le receveur de WAL (PostgreSQL).
 * Jamais l'hôte de la source : seulement ce qui décrit l'état.
 *
 * `lagSeconds` est le retard annoncé par le moteur. Il est trompeur : 0 quand l'applicateur attend alors que le receveur est
 * lui-même en retard. Les jeux `received` et `applied` (GTID, ou position WAL) permettent de le voir, et `pendingApply` dit si
 * des transactions reçues ne sont pas encore appliquées (null : on ne sait pas).
 */
final readonly class ReplicationChannel
{
    public function __construct(
        public string $name,
        public ThreadState $receiver,
        public ThreadState $applier,
        public ?int $lagSeconds,
        public ?string $received,
        public ?string $applied,
        public ?bool $pendingApply,
        public ?ReplicationError $receiverError,
        public ?ReplicationError $applierError,
    ) {
    }
}

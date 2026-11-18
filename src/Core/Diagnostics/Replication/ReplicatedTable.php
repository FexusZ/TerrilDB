<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Replication;

/**
 * Une table répliquée de ClickHouse (`system.replicas`). Ni chemin ZooKeeper ni nom de réplica : seulement l'état.
 */
final readonly class ReplicatedTable
{
    public function __construct(
        public string $database,
        public string $table,
        public bool $readonly,
        public bool $sessionExpired,
        public int $absoluteDelaySeconds,
        public int $queueSize,
        public int $insertsInQueue,
        public int $lostParts,
    ) {
    }

    public function name(): string
    {
        return $this->database.'.'.$this->table;
    }
}

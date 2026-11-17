<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Replication;

/**
 * Les sections propres à un moteur, ajoutées au rapport commun : chacune a son état (`available`, `permission_denied`,
 * `disabled`…) et reste nulle quand le moteur n'a pas la notion.
 */
final readonly class ReplicationExtras
{
    public function __construct(
        /** PostgreSQL : slots de réplication. */
        public ?ReplicationSlots $slots = null,
        /** PostgreSQL (`pg_stat_replication`) et Redis : les réplicas vus depuis la source. */
        public ?ReplicaLinks $replicas = null,
        /** MySQL / MariaDB : journaux binaires. */
        public ?BinlogState $binlog = null,
        /** MySQL / MariaDB : réplication semi-synchrone. */
        public ?SemiSyncState $semiSync = null,
        /** ClickHouse : tables répliquées. */
        public ?ReplicatedTables $tables = null,
        /** Redis : lien avec la source, tampon, décalage. */
        public ?KeyValueReplication $keyValue = null,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Replication;

final readonly class ReplicationAlert
{
    public function __construct(
        public ReplicationAlertCode $code,
        public AlertSeverity $severity,
        /** Nom du canal concerné (vide : le canal par défaut ou une alerte du serveur entier). */
        public string $channel = '',
    ) {
    }
}

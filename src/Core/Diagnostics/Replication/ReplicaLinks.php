<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Replication;

use App\Core\Diagnostics\DiagnosticStatus;

final readonly class ReplicaLinks
{
    /**
     * @param list<ReplicaLink> $replicas
     */
    public function __construct(
        public DiagnosticStatus $status,
        public array $replicas,
    ) {
    }
}

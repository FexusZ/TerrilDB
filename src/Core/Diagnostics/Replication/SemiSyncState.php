<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Replication;

use App\Core\Diagnostics\DiagnosticStatus;

final readonly class SemiSyncState
{
    public function __construct(
        public DiagnosticStatus $status,
        public SemiSyncMode $source,
        public SemiSyncMode $replica,
        /** Réplicas semi-synchrones connectés à cette source (null : inconnu). */
        public ?int $clients,
    ) {
    }
}

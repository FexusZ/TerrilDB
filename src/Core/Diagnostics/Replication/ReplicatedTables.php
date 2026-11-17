<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Replication;

use App\Core\Diagnostics\DiagnosticStatus;

final readonly class ReplicatedTables
{
    /**
     * @param list<ReplicatedTable> $tables
     */
    public function __construct(
        public DiagnosticStatus $status,
        public array $tables,
        public bool $truncated,
    ) {
    }
}

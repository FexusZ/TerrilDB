<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Replication;

use App\Core\Diagnostics\DiagnosticStatus;

/**
 * Les slots de réplication d'un serveur. Une liste vide avec un état `available` est « aucun slot », pas une panne.
 */
final readonly class ReplicationSlots
{
    /**
     * @param list<ReplicationSlot> $slots
     */
    public function __construct(
        public DiagnosticStatus $status,
        public array $slots,
    ) {
    }
}

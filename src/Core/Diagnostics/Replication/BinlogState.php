<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Replication;

use App\Core\Diagnostics\DiagnosticStatus;

/**
 * Les journaux binaires d'un serveur MySQL / MariaDB : actifs ou non, nombre et taille, expiration, jeux de GTID. `expireSeconds`
 * vaut 0 quand rien n'expire jamais, null quand le moteur ne le dit pas.
 */
final readonly class BinlogState
{
    public function __construct(
        public DiagnosticStatus $status,
        public ?bool $enabled,
        public ?int $fileCount,
        public ?int $totalBytes,
        public ?int $expireSeconds,
        /** GTID exécutés (borné) : MySQL `gtid_executed`, MariaDB `gtid_binlog_pos`. */
        public ?string $gtidExecuted,
        /** GTID purgés des journaux (MySQL seulement). */
        public ?string $gtidPurged,
    ) {
    }
}

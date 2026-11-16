<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Replication;

/**
 * Ce que `INFO replication` de Redis dit en plus du rôle : l'état du lien (`up` / `down`), le silence de la source en secondes,
 * une synchronisation complète en cours, le tampon de réplication et le décalage de la source. Jamais l'hôte, le port ni le mot
 * de passe de la source.
 */
final readonly class KeyValueReplication
{
    public function __construct(
        public ?string $linkStatus,
        public ?int $lastIoSecondsAgo,
        public ?bool $syncInProgress,
        public ?int $backlogSize,
        public ?int $masterReplOffset,
    ) {
    }
}

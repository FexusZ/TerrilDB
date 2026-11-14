<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * Une attente : `waiting` attend un verrou que tient `blocking`.
 */
final readonly class LockWait
{
    public function __construct(
        public LockParty $waiting,
        public LockParty $blocking,
        /** Depuis combien de temps le verrou est attendu, si le moteur le dit. */
        public ?int $seconds,
        public LockKind $kind,
        /** Mode du verrou demandé (`X`, `RowExclusiveLock`, `SHARED_READ`…), tel que le moteur le nomme. */
        public ?string $mode,
        /** Sur quoi : table, index ou objet, tel que le moteur le nomme. */
        public ?string $object,
    ) {
    }
}

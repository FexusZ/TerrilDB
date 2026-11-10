<?php

declare(strict_types=1);

namespace App\Http\Web\Security;

/**
 * Ce qu'un échec de connexion vient de déclencher : de quoi le journaliser (genre et durée), jamais le compte ni l'adresse.
 */
final readonly class LockoutOutcome
{
    public function __construct(
        public LockoutKind $kind,
        public int $seconds,
    ) {
    }
}

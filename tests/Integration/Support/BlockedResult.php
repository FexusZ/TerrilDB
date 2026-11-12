<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

/** Issue d'une instruction lancée par BlockedStatement. */
final class BlockedResult
{
    public function __construct(
        public readonly bool $succeeded,
        public readonly string $sqlState,
        public readonly string $driverCode,
        public readonly float $seconds,
    ) {
    }

    /** Délai de verrou : 1205 (InnoDB, MariaDB), 3572 (NOWAIT MySQL), 55P03 (PostgreSQL lock_not_available). */
    public bool $lockTimeout {
        get => !$this->succeeded && ('55P03' === $this->sqlState || \in_array($this->driverCode, ['1205', '3572'], true));
    }
}

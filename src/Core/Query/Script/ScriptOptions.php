<?php

declare(strict_types=1);

namespace App\Core\Query\Script;

use App\Core\Query\Exception\InvalidQueryOptionsException;
use App\Core\Query\QueryOptions;
use App\Core\Query\WritePermissions;

final readonly class ScriptOptions
{
    /** Sous les 120 s du proxy de production : au-delà, le client recevrait un 504 pendant que le script continue. */
    public const DEFAULT_TIMEOUT_MS = 100_000;

    public function __construct(
        public WritePermissions $writes,
        public int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
        public bool $dryRun = false,
    ) {
        if ($timeoutMs < QueryOptions::MIN_TIMEOUT_MS || $timeoutMs > QueryOptions::MAX_TIMEOUT_MS) {
            throw new InvalidQueryOptionsException('The timeout is out of bounds.');
        }
    }
}

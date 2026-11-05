<?php

declare(strict_types=1);

namespace App\Core\Query;

use App\Core\Query\Exception\InvalidQueryOptionsException;

/**
 * Les bornes d'une exécution : mode, durée maximale côté moteur, nombre maximal de lignes rendues, et ce qu'une
 * écriture peut toucher (tout, sauf quand un jeton en accorde moins).
 * Les bornes hautes sont fixes, pour qu'un client ne puisse pas les lever à volonté.
 */
final readonly class QueryOptions
{
    public const MIN_TIMEOUT_MS = 100;
    public const MAX_TIMEOUT_MS = 600_000;
    public const MAX_ROWS = 100_000;

    public function __construct(
        public QueryMode $mode = QueryMode::ReadOnly,
        public int $timeoutMs = 30_000,
        public int $maxRows = 1_000,
        public WritePermissions $writes = new WritePermissions(true, true),
    ) {
        if ($timeoutMs < self::MIN_TIMEOUT_MS || $timeoutMs > self::MAX_TIMEOUT_MS) {
            throw new InvalidQueryOptionsException('The timeout is out of bounds.');
        }
        if ($maxRows < 1 || $maxRows > self::MAX_ROWS) {
            throw new InvalidQueryOptionsException('The row limit is out of bounds.');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\ScriptRun;

use App\Core\Query\Exception\InvalidQueryOptionsException;
use App\Core\Query\QueryOptions;

/**
 * La durée maximale d'une exécution DÉTACHÉE (#169) : elle ne dépend plus du proxy, donc ni des 120 s d'une requête ni des 600 s de la
 * console. Réglage d'installation `TERRILDB_SCRIPT_RUN_MAX_SECONDS` (4 h par défaut, 10 min à 24 h) ; une durée au-dessus est REFUSÉE
 * (422), jamais ramenée en silence. La console et les routes synchrones gardent `RequestTimeLimit`.
 */
final readonly class ScriptRunTimeLimit
{
    public const MIN_SECONDS = 600;
    public const MAX_SECONDS = 86_400;
    /** Ce qu'on applique quand le client ne dit rien : comme avant, sans dépasser le maximum de l'installation. */
    private const DEFAULT_MS = 600_000;

    private int $maxMs;

    public function __construct(int $maxSeconds)
    {
        if ($maxSeconds < self::MIN_SECONDS || $maxSeconds > self::MAX_SECONDS) {
            throw new \InvalidArgumentException(\sprintf('TERRILDB_SCRIPT_RUN_MAX_SECONDS must be between %d and %d seconds.', self::MIN_SECONDS, self::MAX_SECONDS));
        }
        $this->maxMs = $maxSeconds * 1000;
    }

    public function maxMs(): int
    {
        return $this->maxMs;
    }

    public function defaultMs(): int
    {
        return min(self::DEFAULT_MS, $this->maxMs);
    }

    /**
     * @throws InvalidQueryOptionsException
     */
    public function check(int $timeoutMs): int
    {
        if ($timeoutMs < QueryOptions::MIN_TIMEOUT_MS || $timeoutMs > $this->maxMs) {
            throw new InvalidQueryOptionsException('timeoutMs is out of bounds.');
        }

        return $timeoutMs;
    }
}

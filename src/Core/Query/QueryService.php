<?php

declare(strict_types=1);

namespace App\Core\Query;

use App\Core\Driver\Exception\EngineFaultException;
use App\Core\Driver\Exception\LockConflictException;
use App\Core\Driver\TargetConnection;
use App\Core\Guard\Decision;
use App\Core\Guard\GuardReason;
use App\Core\Guard\StatementGuard;
use App\Core\Guard\StatementKind;
use App\Core\Query\Exception\QueryFailedException;
use App\Core\Query\Exception\QueryNeedsConfirmationException;
use App\Core\Query\Exception\QueryRefusedException;
use App\Core\Query\Exception\QueryTimeoutException;

/**
 * Le point d'entrée unique de l'exécution, pour l'interface, l'API et le MCP : le garde examine l'instruction,
 * puis le runner du moteur l'exécute avec les bornes demandées. Un refus est définitif ; une instruction
 * destructive attend une confirmation explicite.
 */
final readonly class QueryService
{
    public function __construct(private StatementGuard $guard)
    {
    }

    /**
     * @throws QueryRefusedException
     * @throws QueryNeedsConfirmationException
     * @throws QueryFailedException
     * @throws QueryTimeoutException
     * @throws LockConflictException
     * @throws EngineFaultException
     */
    public function execute(TargetConnection $target, string $sql, QueryOptions $options = new QueryOptions(), bool $confirmed = false): QueryResult
    {
        $verdict = $this->guard->check($sql, $target->engine, $options->mode, $options->writes, $options->lenient);

        if (Decision::Refused === $verdict->decision || null === $verdict->kind) {
            throw new QueryRefusedException($verdict->reason ?? GuardReason::UnsupportedStatement);
        }
        if (!$confirmed && $options->lenient && StatementKind::Schema === $verdict->kind && self::feedsAStream($target, $sql)) {
            // Console : l'avertissement dit la vraie raison (un flux ne suivra pas), à la place du « destructif » générique ou du silence.
            throw new QueryNeedsConfirmationException(GuardReason::StreamSource);
        }
        if (Decision::NeedsConfirmation === $verdict->decision && !$confirmed) {
            throw new QueryNeedsConfirmationException($verdict->reason ?? GuardReason::DestructiveStatement);
        }

        return $target->runner()->run($sql, $verdict->kind, $options);
    }

    /**
     * Vitess : l'instruction nomme-t-elle une table qui nourrit un flux (Materialize, MoveTables) ? Une modification de structure n'y est pas
     * propagée. Sans flux ou sans lecture possible, rien à dire : l'avertissement n'est jamais un obstacle.
     */
    public static function feedsAStream(TargetConnection $target, string $sql): bool
    {
        foreach ($target->streams()?->sources() ?? [] as $source) {
            if ($source->touchedBy($sql)) {
                return true;
            }
        }

        return false;
    }
}

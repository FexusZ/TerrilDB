<?php

declare(strict_types=1);

namespace App\Core\Query;

use App\Core\Driver\TargetConnection;
use App\Core\Guard\Decision;
use App\Core\Guard\GuardReason;
use App\Core\Guard\StatementGuard;
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
     */
    public function execute(TargetConnection $target, string $sql, QueryOptions $options = new QueryOptions(), bool $confirmed = false): QueryResult
    {
        $verdict = $this->guard->check($sql, $target->engine, $options->mode);

        if (Decision::Refused === $verdict->decision || null === $verdict->kind) {
            throw new QueryRefusedException($verdict->reason ?? GuardReason::UnsupportedStatement);
        }
        if (Decision::NeedsConfirmation === $verdict->decision && !$confirmed) {
            throw new QueryNeedsConfirmationException($verdict->reason ?? GuardReason::DestructiveStatement);
        }

        return $target->runner()->run($sql, $verdict->kind, $options);
    }
}

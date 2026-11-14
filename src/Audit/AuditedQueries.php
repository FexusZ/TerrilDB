<?php

declare(strict_types=1);

namespace App\Audit;

use App\Core\Driver\Exception\LockConflictException;
use App\Core\Driver\TargetConnection;
use App\Core\Query\Exception\QueryFailedException;
use App\Core\Query\Exception\QueryNeedsConfirmationException;
use App\Core\Query\Exception\QueryRefusedException;
use App\Core\Query\Exception\QueryTimeoutException;
use App\Core\Query\QueryOptions;
use App\Core\Query\QueryResult;
use App\Core\Query\QueryService;
use Symfony\Component\Uid\Uuid;

/**
 * Exécute une requête et en garde la trace, qui que soit la porte (interface, API, MCP). Chaque exécution, refus ou
 * échec est un événement ; une simple demande de confirmation n'en est pas un, c'est la requête confirmée qui compte.
 * Le texte de l'instruction est conservé : c'est ce qui permet de savoir ce qu'un outil a réellement demandé.
 */
final readonly class AuditedQueries
{
    private const MAX_ERROR_LENGTH = 500;

    public function __construct(
        private QueryService $queries,
        private AuditLog $audit,
    ) {
    }

    /**
     * @param array<string, bool|int|string> $extra       détails ajoutés à l'événement (par exemple la nature d'un export)
     * @param string|null                    $recordedSql texte à journaliser à la place de l'instruction exécutée (le SQL de l'utilisateur, pas l'EXPLAIN construit)
     */
    public function execute(AuditContext $context, Uuid $connectionId, TargetConnection $target, string $sql, QueryOptions $options, bool $confirmed = false, array $extra = [], ?string $recordedSql = null): QueryResult
    {
        $base = ['sql' => $recordedSql ?? $sql, 'mode' => $options->mode->value, 'engine' => $target->engine->value, 'confirmed' => $confirmed] + $extra;

        try {
            $result = $this->queries->execute($target, $sql, $options, $confirmed);
        } catch (QueryNeedsConfirmationException $e) {
            throw $e;
        } catch (QueryRefusedException $e) {
            $this->audit->record($context, AuditAction::QueryRefused, AuditOutcome::Refused, $connectionId, $base + ['reason' => $e->reason->value]);

            throw $e;
        } catch (QueryTimeoutException $e) {
            $this->audit->record($context, AuditAction::QueryFailed, AuditOutcome::Failed, $connectionId, $base + ['reason' => 'timeout', 'timeoutMs' => $options->timeoutMs]);

            throw $e;
        } catch (LockConflictException $e) {
            $this->audit->record($context, AuditAction::QueryFailed, AuditOutcome::Failed, $connectionId, $base + ['reason' => $e->conflict->code()]);

            throw $e;
        } catch (QueryFailedException $e) {
            $this->audit->record($context, AuditAction::QueryFailed, AuditOutcome::Failed, $connectionId, $base + ['reason' => 'engine_error', 'error' => mb_substr($e->getMessage(), 0, self::MAX_ERROR_LENGTH)]);

            throw $e;
        }

        $this->audit->record($context, AuditAction::QueryExecuted, AuditOutcome::Success, $connectionId, $base + [
            'rowCount' => $result->rowCount(),
            'affectedRows' => $result->affectedRows,
            'truncated' => $result->truncated,
            'durationMs' => $result->durationMs,
        ]);

        return $result;
    }
}

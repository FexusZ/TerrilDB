<?php

declare(strict_types=1);

namespace App\Audit;

use App\Core\Driver\Exception\UnsupportedOperationException;
use App\Core\Driver\TargetConnection;
use App\Core\Guard\Exception\ScriptTooLargeException;
use App\Core\Guard\Exception\UnsplittableScriptException;
use App\Core\Query\Exception\QueryRefusedException;
use App\Core\Query\Script\Exception\ScriptDryRunUnavailableException;
use App\Core\Query\Script\Exception\ScriptNeedsConfirmationException;
use App\Core\Query\Script\Exception\ScriptRefusedException;
use App\Core\Query\Script\ScriptOptions;
use App\Core\Query\Script\ScriptService;
use App\Core\Query\Script\ScriptStepResult;
use App\Core\Query\Script\ScriptStepStatus;
use Symfony\Component\Uid\Uuid;

/**
 * Exécute un script et en garde la trace, qui que soit la porte : UNE ligne par instruction réellement envoyée au
 * moteur (exécutée, annulée, en échec), toutes liées par le même identifiant d'exécution, avec leur rang (« 3 sur 12 »).
 * Une instruction qui n'est jamais partie n'a pas de ligne ; un refus est consigné une fois, sur l'instruction fautive.
 * Une simple demande de confirmation n'est pas un événement. Le texte exact de chaque instruction est conservé.
 */
final readonly class AuditedScripts
{
    public function __construct(
        private ScriptService $scripts,
        private AuditLog $audit,
    ) {
    }

    /**
     * @throws ScriptRefusedException
     */
    public function execute(AuditContext $context, Uuid $connectionId, TargetConnection $target, string $script, ScriptOptions $options, bool $confirmed = false): AuditedScriptRun
    {
        $executionId = Uuid::v7();
        $base = ['mode' => 'read_write', 'engine' => $target->engine->value, 'confirmed' => $confirmed, 'script' => true, 'executionId' => $executionId->toRfc4122(), 'dryRun' => $options->dryRun];

        try {
            $result = $this->scripts->execute($target, $script, $options, $confirmed);
        } catch (ScriptRefusedException $e) {
            $preview = $this->scripts->preview($script, $target->engine, $options->writes);
            $this->audit->record($context, AuditAction::QueryRefused, AuditOutcome::Refused, $connectionId, $base + [
                'sql' => $preview->report->steps[$e->statementIndex]->sql ?? '',
                'reason' => $e->reason->value,
                'rank' => $e->statementIndex + 1,
                'total' => \count($preview->report->steps),
            ]);

            throw $e;
        } catch (ScriptNeedsConfirmationException|ScriptDryRunUnavailableException|UnsplittableScriptException|ScriptTooLargeException|QueryRefusedException|UnsupportedOperationException $e) {
            // Refusés AVANT toute exécution : rien n'est parti, rien à consigner de plus.
            throw $e;
        } catch (\Throwable $e) {
            // Ce que le moteur a pu valider avant l'accident est inconnu : la trace dit seulement que l'exécution a échoué, sans le texte de l'erreur.
            $this->audit->record($context, AuditAction::QueryFailed, AuditOutcome::Failed, $connectionId, $base + ['sql' => $script, 'reason' => 'executor_error']);

            throw $e;
        }

        $total = \count($result->steps);
        foreach ($result->steps as $step) {
            if (ScriptStepStatus::NotRun === $step->status) {
                continue;
            }
            $this->recordStep($context, $connectionId, $base + ['rank' => $step->index + 1, 'total' => $total, 'status' => $step->status->value], $step, $result->failureReason);
        }

        return new AuditedScriptRun($executionId, $result);
    }

    /**
     * @param array<string, bool|int|string|null> $details
     */
    private function recordStep(AuditContext $context, Uuid $connectionId, array $details, ScriptStepResult $step, ?string $failureReason): void
    {
        $details += ['sql' => $step->sql, 'durationMs' => $step->durationMs];
        if (ScriptStepStatus::Failed === $step->status) {
            $this->audit->record($context, AuditAction::QueryFailed, AuditOutcome::Failed, $connectionId, $details + ['reason' => $failureReason ?? 'engine_error', 'sqlstate' => $step->errorCode]);

            return;
        }
        $this->audit->record($context, AuditAction::QueryExecuted, AuditOutcome::Success, $connectionId, $details + ['affectedRows' => $step->affectedRows, 'rowCount' => $step->returnedRows]);
    }
}

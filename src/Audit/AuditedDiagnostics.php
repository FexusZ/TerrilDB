<?php

declare(strict_types=1);

namespace App\Audit;

use App\Core\Diagnostics\Exception\DiagnosticsFailedException;
use App\Core\Diagnostics\LockReport;
use App\Core\Diagnostics\SessionReport;
use App\Core\Diagnostics\VariableQuery;
use App\Core\Diagnostics\VariableReport;
use App\Core\Driver\TargetConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Lit la supervision d'un serveur et en garde la trace, qui que soit la porte (interface, API, MCP) : qui a lu quelle page, sur
 * quelle connexion, avec quel état et combien de lignes. Jamais ce que la page contenait : les textes de requêtes des autres
 * sessions ne sont recopiés nulle part dans le journal.
 */
final readonly class AuditedDiagnostics
{
    public function __construct(private AuditLog $audit)
    {
    }

    /**
     * @throws DiagnosticsFailedException
     */
    public function sessions(AuditContext $context, Uuid $connectionId, TargetConnection $target): SessionReport
    {
        $base = ['topic' => 'sessions', 'engine' => $target->engine->value];

        try {
            $report = $target->diagnostics()->sessions()->sessions();
        } catch (DiagnosticsFailedException $e) {
            $this->audit->record($context, AuditAction::DiagnosticRead, AuditOutcome::Failed, $connectionId, $base);

            throw $e;
        }

        $this->audit->record($context, AuditAction::DiagnosticRead, AuditOutcome::Success, $connectionId, $base + ['status' => $report->status->availability->value, 'rows' => \count($report->sessions)]);

        return $report;
    }

    /**
     * @throws DiagnosticsFailedException
     */
    public function locks(AuditContext $context, Uuid $connectionId, TargetConnection $target): LockReport
    {
        $base = ['topic' => 'locks', 'engine' => $target->engine->value];

        try {
            $report = $target->diagnostics()->locks()->locks();
        } catch (DiagnosticsFailedException $e) {
            $this->audit->record($context, AuditAction::DiagnosticRead, AuditOutcome::Failed, $connectionId, $base);

            throw $e;
        }

        $this->audit->record($context, AuditAction::DiagnosticRead, AuditOutcome::Success, $connectionId, $base + ['status' => $report->status->availability->value, 'transactions' => \count($report->transactions), 'waits' => \count($report->waits)]);

        return $report;
    }

    /**
     * Ni les valeurs lues ni le texte cherché ne sont journalisés : seulement l'état, le nombre de lignes et le filtre « modifiées ».
     *
     * @throws DiagnosticsFailedException
     */
    public function variables(AuditContext $context, Uuid $connectionId, TargetConnection $target, VariableQuery $query): VariableReport
    {
        $base = ['topic' => 'variables', 'engine' => $target->engine->value, 'changedOnly' => $query->changedOnly];

        try {
            $report = $target->diagnostics()->variables()->variables($query);
        } catch (DiagnosticsFailedException $e) {
            $this->audit->record($context, AuditAction::DiagnosticRead, AuditOutcome::Failed, $connectionId, $base);

            throw $e;
        }

        $this->audit->record($context, AuditAction::DiagnosticRead, AuditOutcome::Success, $connectionId, $base + ['status' => $report->status->availability->value, 'rows' => \count($report->variables)]);

        return $report;
    }
}

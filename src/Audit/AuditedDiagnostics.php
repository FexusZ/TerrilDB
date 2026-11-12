<?php

declare(strict_types=1);

namespace App\Audit;

use App\Core\Diagnostics\Exception\DiagnosticsFailedException;
use App\Core\Diagnostics\Health\HealthReport;
use App\Core\Diagnostics\Health\HealthSampler;
use App\Core\Diagnostics\LockReport;
use App\Core\Diagnostics\SessionReport;
use App\Core\Driver\TargetConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Lit la supervision d'un serveur et en garde la trace, qui que soit la porte (interface, API, MCP) : qui a lu quelle page, sur
 * quelle connexion, avec quel état et combien de lignes. Jamais ce que la page contenait : les textes de requêtes des autres
 * sessions ne sont recopiés nulle part dans le journal.
 */
final readonly class AuditedDiagnostics
{
    public function __construct(private AuditLog $audit, private HealthSampler $sampler)
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
     * Santé par deux relevés : le journal garde l'état, l'intervalle demandé et si la mesure a été écartée, jamais un compteur.
     *
     * @throws DiagnosticsFailedException
     */
    public function health(AuditContext $context, Uuid $connectionId, TargetConnection $target, int $seconds): HealthReport
    {
        $base = ['topic' => 'health', 'engine' => $target->engine->value];

        try {
            $report = $this->sampler->measure($target->diagnostics()->health(), $seconds);
        } catch (DiagnosticsFailedException $e) {
            $this->audit->record($context, AuditAction::DiagnosticRead, AuditOutcome::Failed, $connectionId, $base);

            throw $e;
        }

        $this->audit->record($context, AuditAction::DiagnosticRead, AuditOutcome::Success, $connectionId, $base + ['status' => $report->status->availability->value, 'seconds' => $seconds, 'discarded' => null !== $report->discarded]);

        return $report;
    }
}

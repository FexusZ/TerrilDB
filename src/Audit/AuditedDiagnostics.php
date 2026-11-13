<?php

declare(strict_types=1);

namespace App\Audit;

use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\DigestQuery;
use App\Core\Diagnostics\DigestReport;
use App\Core\Diagnostics\Exception\DiagnosticsFailedException;
use App\Core\Diagnostics\LockReport;
use App\Core\Diagnostics\SessionReport;
use App\Core\Driver\TargetConnection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * Lit la supervision d'un serveur et en garde la trace, qui que soit la porte (interface, API, MCP) : qui a lu quelle page, sur
 * quelle connexion, avec quel état et combien de lignes. Jamais ce que la page contenait : les textes de requêtes des autres
 * sessions ne sont recopiés nulle part dans le journal.
 */
final readonly class AuditedDiagnostics
{
    public function __construct(private AuditLog $audit, private LoggerInterface $logger = new NullLogger())
    {
    }

    /**
     * Ce que chaque thème peut lire. Un thème dont la vérification échoue rend l'état « erreur » sans masquer les autres ; la
     * cause est consignée (nom du thème et classe de l'exception), jamais le message du moteur, qui peut citer un hôte ou un compte.
     *
     * @return array<string, DiagnosticStatus>
     */
    public function capabilities(TargetConnection $target): array
    {
        return $target->diagnostics()->capabilities(function (string $theme, DiagnosticsFailedException $failure): void {
            $this->logger->error('A diagnostics theme could not be checked.', ['theme' => $theme, 'reason' => ($failure->getPrevious() ?? $failure)::class]);
        });
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
     * Seuls le classement, la limite, l'état et le nombre d'empreintes sont journalisés : jamais le texte d'une empreinte.
     *
     * @throws DiagnosticsFailedException
     */
    public function statements(AuditContext $context, Uuid $connectionId, TargetConnection $target, DigestQuery $query): DigestReport
    {
        $base = ['topic' => 'top_queries', 'engine' => $target->engine->value, 'order' => $query->order->value, 'limit' => $query->limit, 'hours' => $query->hours];

        try {
            $report = $target->diagnostics()->statements()->top($query);
        } catch (DiagnosticsFailedException $e) {
            $this->audit->record($context, AuditAction::DiagnosticRead, AuditOutcome::Failed, $connectionId, $base);

            throw $e;
        }

        $this->audit->record($context, AuditAction::DiagnosticRead, AuditOutcome::Success, $connectionId, $base + ['status' => $report->status->availability->value, 'rows' => \count($report->digests)]);

        return $report;
    }
}

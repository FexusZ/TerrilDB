<?php

declare(strict_types=1);

namespace App\Audit;

use App\Core\Diagnostics\AccountReport;
use App\Core\Diagnostics\ConfigAuditor;
use App\Core\Diagnostics\ConfigAuditReport;
use App\Core\Diagnostics\ConnectionEncryption;
use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\DigestQuery;
use App\Core\Diagnostics\DigestReport;
use App\Core\Diagnostics\Exception\DiagnosticsFailedException;
use App\Core\Diagnostics\Exception\InvalidVariableQueryException;
use App\Core\Diagnostics\Health\HealthBusyException;
use App\Core\Diagnostics\Health\HealthReport;
use App\Core\Diagnostics\Health\HealthSampler;
use App\Core\Diagnostics\Health\HealthSlots;
use App\Core\Diagnostics\LockReport;
use App\Core\Diagnostics\Log\ErrorLogReport;
use App\Core\Diagnostics\Log\SlowQueryReport;
use App\Core\Diagnostics\Replication\ReplicationReport;
use App\Core\Diagnostics\SessionReport;
use App\Core\Diagnostics\VariableComparer;
use App\Core\Diagnostics\VariableComparison;
use App\Core\Diagnostics\VariableQuery;
use App\Core\Diagnostics\VariableReport;
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
    public function __construct(private AuditLog $audit, private HealthSampler $sampler, private HealthSlots $slots, private LoggerInterface $logger = new NullLogger())
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

    /**
     * Comptes et privilèges du serveur : le journal garde l'état et les nombres, jamais un nom de compte ni un droit.
     *
     * @throws DiagnosticsFailedException
     */
    public function accounts(AuditContext $context, Uuid $connectionId, TargetConnection $target): AccountReport
    {
        $base = ['topic' => 'accounts', 'engine' => $target->engine->value];

        try {
            $report = $target->diagnostics()->accounts()->accounts();
        } catch (DiagnosticsFailedException $e) {
            $this->audit->record($context, AuditAction::DiagnosticRead, AuditOutcome::Failed, $connectionId, $base);

            throw $e;
        }

        $this->audit->record($context, AuditAction::DiagnosticRead, AuditOutcome::Success, $connectionId, $base + ['status' => $report->status->availability->value, 'rows' => \count($report->accounts), 'findings' => \count($report->findings())]);

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

    /**
     * Audit de configuration : les variables que les règles regardent sont lues par nom, jugées sans rien exécuter. Le journal garde
     * l'état et des nombres, ni nom de variable ni valeur.
     *
     * @throws DiagnosticsFailedException
     */
    public function configAudit(AuditContext $context, Uuid $connectionId, TargetConnection $target): ConfigAuditReport
    {
        $base = ['topic' => 'config_audit', 'engine' => $target->engine->value];

        try {
            $variables = $target->diagnostics()->variables();
            $names = ConfigAuditor::namesFor($target->engine);
            $read = [] === $names ? new VariableReport($variables->status(), [], false) : $variables->variables(new VariableQuery(limit: VariableQuery::MAX_LIMIT, names: $names));
        } catch (DiagnosticsFailedException $e) {
            $this->audit->record($context, AuditAction::DiagnosticRead, AuditOutcome::Failed, $connectionId, $base);

            throw $e;
        }
        $report = (new ConfigAuditor())->audit($target->engine, $read);

        $this->audit->record($context, AuditAction::DiagnosticRead, AuditOutcome::Success, $connectionId, $base + ['status' => $report->status->availability->value, 'rows' => $report->evaluated, 'findings' => \count($report->findings)]);

        return $report;
    }

    /**
     * Écart de variables entre deux serveurs du même moteur. Le journal garde les deux connexions, l'état et des nombres : jamais
     * un nom de variable ni une valeur.
     *
     * @throws DiagnosticsFailedException
     * @throws InvalidVariableQueryException deux moteurs différents
     */
    public function compareVariables(AuditContext $context, Uuid $idA, TargetConnection $a, Uuid $idB, TargetConnection $b): VariableComparison
    {
        if ($a->engine !== $b->engine) {
            throw new InvalidVariableQueryException('Only two connections of the same engine can be compared.');
        }
        $base = ['topic' => 'compare_variables', 'engine' => $a->engine->value, 'with' => $idB->toRfc4122()];

        try {
            $comparison = (new VariableComparer())->compare($a->diagnostics()->variables(), $b->diagnostics()->variables());
        } catch (DiagnosticsFailedException $e) {
            $this->audit->record($context, AuditAction::DiagnosticRead, AuditOutcome::Failed, $idA, $base);

            throw $e;
        }

        $this->audit->record($context, AuditAction::DiagnosticRead, AuditOutcome::Success, $idA, $base + ['status' => $comparison->status->availability->value, 'differences' => \count($comparison->differences), 'masked' => $comparison->masked, 'truncated' => $comparison->truncated]);

        return $comparison;
    }

    /**
     * Chiffrement de la connexion : le journal ne garde que l'état et le fait d'être chiffrée.
     *
     * @throws DiagnosticsFailedException
     */
    public function encryption(AuditContext $context, Uuid $connectionId, TargetConnection $target): ConnectionEncryption
    {
        $base = ['topic' => 'encryption', 'engine' => $target->engine->value];

        try {
            $encryption = $target->diagnostics()->encryption()->encryption();
        } catch (DiagnosticsFailedException $e) {
            $this->audit->record($context, AuditAction::DiagnosticRead, AuditOutcome::Failed, $connectionId, $base);

            throw $e;
        }

        $this->audit->record($context, AuditAction::DiagnosticRead, AuditOutcome::Success, $connectionId, $base + ['status' => 'available', 'encrypted' => $encryption->encrypted]);

        return $encryption;
    }

    /**
     * Santé par deux relevés : le journal garde l'état, l'intervalle demandé et si la mesure a été écartée, jamais un compteur.
     *
     * @throws DiagnosticsFailedException
     * @throws HealthBusyException        si le compte a déjà ses mesures en cours
     */
    public function health(AuditContext $context, Uuid $connectionId, TargetConnection $target, int $seconds): HealthReport
    {
        $base = ['topic' => 'health', 'engine' => $target->engine->value];

        try {
            $report = $this->slots->run($context->userId?->toRfc4122() ?? 'anonymous', fn (): HealthReport => $this->sampler->measure($target->diagnostics()->health(), $seconds));
        } catch (DiagnosticsFailedException $e) {
            $this->audit->record($context, AuditAction::DiagnosticRead, AuditOutcome::Failed, $connectionId, $base);

            throw $e;
        }

        $this->audit->record($context, AuditAction::DiagnosticRead, AuditOutcome::Success, $connectionId, $base + ['status' => $report->status->availability->value, 'seconds' => $seconds, 'discarded' => null !== $report->discarded]);

        return $report;
    }

    /**
     * Seuls l'état, le rôle et des nombres sont journalisés : jamais un texte d'erreur de réplication, qui peut citer une valeur de donnée.
     *
     * @throws DiagnosticsFailedException
     */
    public function replication(AuditContext $context, Uuid $connectionId, TargetConnection $target): ReplicationReport
    {
        $base = ['topic' => 'replication', 'engine' => $target->engine->value];

        try {
            $report = $target->diagnostics()->replication()->replication();
        } catch (DiagnosticsFailedException $e) {
            $this->audit->record($context, AuditAction::DiagnosticRead, AuditOutcome::Failed, $connectionId, $base);

            throw $e;
        }

        $this->audit->record($context, AuditAction::DiagnosticRead, AuditOutcome::Success, $connectionId, $base + ['status' => $report->status->availability->value, 'role' => $report->role->value, 'channels' => \count($report->channels), 'alerts' => \count($report->alerts())]);

        return $report;
    }

    /**
     * Journaux du serveur : le journal d'audit garde l'état et le nombre de lignes de chaque source, JAMAIS une ligne ni un texte
     * (un journal d'erreurs ou de requêtes lentes peut citer des comptes, des adresses, des valeurs).
     *
     * @return array{ErrorLogReport, SlowQueryReport}
     *
     * @throws DiagnosticsFailedException
     */
    public function logs(AuditContext $context, Uuid $connectionId, TargetConnection $target): array
    {
        $base = ['topic' => 'logs', 'engine' => $target->engine->value];

        try {
            $logs = $target->diagnostics()->logs();
            $error = $logs->errorLog();
            $slow = $logs->slowQueries();
        } catch (DiagnosticsFailedException $e) {
            $this->audit->record($context, AuditAction::DiagnosticRead, AuditOutcome::Failed, $connectionId, $base);

            throw $e;
        }

        $this->audit->record($context, AuditAction::DiagnosticRead, AuditOutcome::Success, $connectionId, $base + [
            'errorStatus' => $error->status->availability->value,
            'errorRows' => \count($error->entries),
            'slowStatus' => $slow->status->availability->value,
            'slowRows' => \count($slow->queries),
        ]);

        return [$error, $slow];
    }
}

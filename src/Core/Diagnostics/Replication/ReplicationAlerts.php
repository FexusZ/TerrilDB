<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Replication;

/**
 * Règles d'alerte pures, sans accès au serveur. Seuils de retard fixes : 30 s avertit, 300 s est critique (non réglables).
 * Un serveur sans réplication (ou illisible) ne produit aucune alerte.
 */
final class ReplicationAlerts
{
    public const LAG_WARNING_SECONDS = 30;
    public const LAG_CRITICAL_SECONDS = 300;

    /**
     * @return list<ReplicationAlert>
     */
    public static function evaluate(ReplicationReport $report): array
    {
        if (!$report->status->isAvailable() || ReplicationRole::None === $report->role) {
            return [];
        }

        $alerts = [];
        foreach ($report->channels as $channel) {
            array_push($alerts, ...self::channel($channel));
        }

        $settings = $report->settings;
        if (ReplicationRole::Replica === $report->role && false === $settings->writesRefused()) {
            $alerts[] = new ReplicationAlert(ReplicationAlertCode::WritableReplica, AlertSeverity::Warning);
        }
        if (null !== $settings->binlogFormat && 'ROW' !== strtoupper($settings->binlogFormat)) {
            $alerts[] = new ReplicationAlert(ReplicationAlertCode::BinlogFormatNotRow, AlertSeverity::Warning);
        }
        if (false === $settings->gtid) {
            $alerts[] = new ReplicationAlert(ReplicationAlertCode::GtidDisabled, AlertSeverity::Warning);
        }

        return $alerts;
    }

    /**
     * @return list<ReplicationAlert>
     */
    private static function channel(ReplicationChannel $channel): array
    {
        $alerts = [];
        $add = static function (ReplicationAlertCode $code, AlertSeverity $severity) use (&$alerts, $channel): void {
            $alerts[] = new ReplicationAlert($code, $severity, $channel->name);
        };

        match ($channel->receiver) {
            ThreadState::Stopped => $add(ReplicationAlertCode::ReceiverStopped, AlertSeverity::Critical),
            ThreadState::Connecting => $add(ReplicationAlertCode::ReceiverConnecting, AlertSeverity::Warning),
            ThreadState::Running => null,
        };
        if (ThreadState::Stopped === $channel->applier) {
            $add(ReplicationAlertCode::ApplierStopped, AlertSeverity::Critical);
        }
        if (null !== $channel->receiverError) {
            $add(ReplicationAlertCode::ReceiverError, AlertSeverity::Critical);
        }
        if (null !== $channel->applierError) {
            $add(ReplicationAlertCode::ApplierError, AlertSeverity::Critical);
        }

        // Un retard inconnu n'a de sens que tant que l'applicateur tourne : arrêté, le moteur n'en annonce pas.
        $lag = $channel->lagSeconds;
        if (null === $lag) {
            if (ThreadState::Running === $channel->applier) {
                $add(ReplicationAlertCode::LagUnknown, AlertSeverity::Warning);
            }
        } elseif ($lag >= self::LAG_CRITICAL_SECONDS) {
            $add(ReplicationAlertCode::LagCritical, AlertSeverity::Critical);
        } elseif ($lag >= self::LAG_WARNING_SECONDS) {
            $add(ReplicationAlertCode::LagHigh, AlertSeverity::Warning);
        }

        return $alerts;
    }
}

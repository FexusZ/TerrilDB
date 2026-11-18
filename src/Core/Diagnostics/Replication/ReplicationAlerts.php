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
    /** WAL retenu par un slot : 1 Gio avertit, 10 Gio est critique. */
    public const SLOT_RETAINED_WARNING_BYTES = 1073741824;
    public const SLOT_RETAINED_CRITICAL_BYTES = 10737418240;
    /** File d'attente d'une table répliquée (ClickHouse) : 100 entrées avertissent. */
    public const TABLE_QUEUE_WARNING = 100;

    /**
     * @return list<ReplicationAlert>
     */
    public static function evaluate(ReplicationReport $report): array
    {
        if (!$report->status->isAvailable()) {
            return [];
        }

        $extras = self::extras($report->extras);
        if (ReplicationRole::None === $report->role || ReplicationRole::Peer === $report->role) {
            return $extras;
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

        return [...$alerts, ...$extras];
    }

    /**
     * Les sections propres au moteur, jugées quel que soit le rôle : un slot inactif retient du WAL même sans réplica connecté.
     * Une section illisible (droit, source éteinte) ne dit rien.
     *
     * @return list<ReplicationAlert>
     */
    private static function extras(ReplicationExtras $extras): array
    {
        $alerts = [];
        $add = static function (ReplicationAlertCode $code, AlertSeverity $severity, string $channel = '') use (&$alerts): void {
            $alerts[] = new ReplicationAlert($code, $severity, $channel);
        };

        if (null !== $extras->slots && $extras->slots->status->isAvailable()) {
            foreach ($extras->slots->slots as $slot) {
                if (!$slot->active && !$slot->temporary) {
                    $add(ReplicationAlertCode::SlotInactive, AlertSeverity::Warning, $slot->name);
                }
                if (null !== $slot->retainedBytes && $slot->retainedBytes >= self::SLOT_RETAINED_WARNING_BYTES) {
                    $add(ReplicationAlertCode::SlotRetainsWal, $slot->retainedBytes >= self::SLOT_RETAINED_CRITICAL_BYTES ? AlertSeverity::Critical : AlertSeverity::Warning, $slot->name);
                }
                match ($slot->walStatus) {
                    'unreserved' => $add(ReplicationAlertCode::SlotWalUnreserved, AlertSeverity::Warning, $slot->name),
                    'lost' => $add(ReplicationAlertCode::SlotWalLost, AlertSeverity::Critical, $slot->name),
                    default => null,
                };
            }
        }

        if (null !== $extras->replicas && $extras->replicas->status->isAvailable()) {
            foreach ($extras->replicas->replicas as $link) {
                $rank = (string) $link->rank;
                if (!$link->connected) {
                    $add(ReplicationAlertCode::ReplicaNotStreaming, AlertSeverity::Warning, $rank);
                }
                $lag = $link->worstLagSeconds();
                if (null !== $lag && $lag >= self::LAG_CRITICAL_SECONDS) {
                    $add(ReplicationAlertCode::ReplicaLagCritical, AlertSeverity::Critical, $rank);
                } elseif (null !== $lag && $lag >= self::LAG_WARNING_SECONDS) {
                    $add(ReplicationAlertCode::ReplicaLagHigh, AlertSeverity::Warning, $rank);
                }
            }
        }

        $binlog = $extras->binlog;
        if (null !== $binlog && $binlog->status->isAvailable() && true === $binlog->enabled && 0 === $binlog->expireSeconds) {
            $add(ReplicationAlertCode::BinlogNoExpiry, AlertSeverity::Warning);
        }

        $semi = $extras->semiSync;
        if (null !== $semi && $semi->status->isAvailable() && (SemiSyncMode::FallenBack === $semi->source || SemiSyncMode::FallenBack === $semi->replica)) {
            $add(ReplicationAlertCode::SemiSyncFallback, AlertSeverity::Warning);
        }

        if (null !== $extras->tables && $extras->tables->status->isAvailable()) {
            foreach ($extras->tables->tables as $table) {
                $name = $table->name();
                if ($table->readonly) {
                    $add(ReplicationAlertCode::TableReadonly, AlertSeverity::Critical, $name);
                }
                if ($table->sessionExpired) {
                    $add(ReplicationAlertCode::TableSessionExpired, AlertSeverity::Critical, $name);
                }
                if ($table->absoluteDelaySeconds >= self::LAG_CRITICAL_SECONDS) {
                    $add(ReplicationAlertCode::TableLagCritical, AlertSeverity::Critical, $name);
                } elseif ($table->absoluteDelaySeconds >= self::LAG_WARNING_SECONDS) {
                    $add(ReplicationAlertCode::TableLagHigh, AlertSeverity::Warning, $name);
                }
                if ($table->queueSize >= self::TABLE_QUEUE_WARNING) {
                    $add(ReplicationAlertCode::TableQueueHigh, AlertSeverity::Warning, $name);
                }
                if ($table->lostParts > 0) {
                    $add(ReplicationAlertCode::TableLostParts, AlertSeverity::Critical, $name);
                }
            }
        }

        $idle = $extras->keyValue?->lastIoSecondsAgo;
        if (null !== $idle && $idle >= self::LAG_WARNING_SECONDS) {
            $add(ReplicationAlertCode::LinkIdle, $idle >= self::LAG_CRITICAL_SECONDS ? AlertSeverity::Critical : AlertSeverity::Warning);
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
            if ($channel->reportsLag && ThreadState::Running === $channel->applier) {
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

<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Replication;

/**
 * Codes stables des alertes ; le texte français est celui de l'interface.
 */
enum ReplicationAlertCode: string
{
    case ReceiverStopped = 'receiver_stopped';
    case ReceiverConnecting = 'receiver_connecting';
    case ApplierStopped = 'applier_stopped';
    case ReceiverError = 'receiver_error';
    case ApplierError = 'applier_error';
    case LagHigh = 'lag_high';
    case LagCritical = 'lag_critical';
    case LagUnknown = 'lag_unknown';
    case WritableReplica = 'writable_replica';
    case BinlogFormatNotRow = 'binlog_format_not_row';
    case GtidDisabled = 'gtid_disabled';
    case SlotInactive = 'slot_inactive';
    case SlotRetainsWal = 'slot_retains_wal';
    case SlotWalUnreserved = 'slot_wal_unreserved';
    case SlotWalLost = 'slot_wal_lost';
    case ReplicaNotStreaming = 'replica_not_streaming';
    case ReplicaLagHigh = 'replica_lag_high';
    case ReplicaLagCritical = 'replica_lag_critical';
    case BinlogNoExpiry = 'binlog_no_expiry';
    case SemiSyncFallback = 'semisync_fallback';
    case TableReadonly = 'table_readonly';
    case TableSessionExpired = 'table_session_expired';
    case TableLagHigh = 'table_lag_high';
    case TableLagCritical = 'table_lag_critical';
    case TableQueueHigh = 'table_queue_high';
    case TableLostParts = 'table_lost_parts';
    case LinkIdle = 'link_idle';
}

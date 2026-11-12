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
}

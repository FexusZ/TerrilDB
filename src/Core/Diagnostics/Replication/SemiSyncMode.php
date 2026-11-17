<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Replication;

enum SemiSyncMode: string
{
    /** Le greffon n'est pas chargé (MySQL) : aucune variable `rpl_semi_sync_*`. */
    case NotLoaded = 'not_loaded';
    case Off = 'off';
    /** Activé et effectivement semi-synchrone. */
    case Active = 'active';
    /** Activé mais repassé en asynchrone (délai d'acquittement dépassé ou plus de réplica semi-synchrone). */
    case FallenBack = 'fallen_back';
}

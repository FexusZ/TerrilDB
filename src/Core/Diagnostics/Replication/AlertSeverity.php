<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Replication;

enum AlertSeverity: string
{
    case Warning = 'warning';
    case Critical = 'critical';
}

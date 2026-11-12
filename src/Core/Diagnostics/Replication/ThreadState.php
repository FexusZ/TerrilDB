<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Replication;

/**
 * L'état d'un des deux fils d'une réplication (le receveur qui lit le journal de la source, l'applicateur qui le rejoue).
 */
enum ThreadState: string
{
    case Running = 'running';
    /** Le receveur tente de joindre la source (MySQL « Connecting »). */
    case Connecting = 'connecting';
    case Stopped = 'stopped';
}

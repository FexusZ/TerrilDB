<?php

declare(strict_types=1);

namespace App\Core\Query\Script;

enum ScriptStepStatus: string
{
    /** Exécutée et durable. */
    case Applied = 'applied';
    /** Exécutée, puis annulée (jeu à blanc ou échec plus loin dans une transaction). */
    case RolledBack = 'rolled_back';
    case Failed = 'failed';
    case NotRun = 'not_run';
}

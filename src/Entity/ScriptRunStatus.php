<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Où en est l'exécution d'un script. `queued` et `running` sont les seuls états vivants ; tous les autres terminent l'exécution.
 * `interrupted` n'est jamais choisi par le worker : c'est l'état d'une exécution dont plus rien ne donne signe de vie.
 */
enum ScriptRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Committed = 'committed';
    case DryRun = 'dry_run';
    case RolledBack = 'rolled_back';
    case Partial = 'partial';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Interrupted = 'interrupted';

    public function isTerminal(): bool
    {
        return self::Queued !== $this && self::Running !== $this;
    }

    /**
     * Ce que le worker peut annoncer à la fin : tout état terminal sauf l'interruption.
     */
    public function canBeReportedAtTheEnd(): bool
    {
        return $this->isTerminal() && self::Interrupted !== $this;
    }
}

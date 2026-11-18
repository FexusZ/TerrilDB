<?php

declare(strict_types=1);

namespace App\ScriptRun\Channel;

/**
 * Un processus du worker qui joue UNE exécution.
 */
interface ScriptChild
{
    public function isRunning(): bool;
}

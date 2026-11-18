<?php

declare(strict_types=1);

namespace App\ScriptRun\Channel;

/**
 * Un vrai processus (`proc_open`) : « tourne encore » se lit à son état, puis il est refermé dès qu'il a fini.
 */
final class ProcessScriptChild implements ScriptChild
{
    /** @var resource|null */
    private $process;

    /**
     * @param resource $process
     */
    public function __construct($process)
    {
        $this->process = $process;
    }

    public function isRunning(): bool
    {
        if (!\is_resource($this->process)) {
            return false;
        }
        if (proc_get_status($this->process)['running']) {
            return true;
        }
        proc_close($this->process);
        $this->process = null;

        return false;
    }
}

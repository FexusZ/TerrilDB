<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Query\Script\ScriptProgress;
use App\Core\Query\Script\ScriptStepResult;

/**
 * Un observateur de test : note ce qu'il voit et demande l'arrêt une fois `$stopAfter` instructions exécutées (null = jamais).
 */
final class RecordingScriptProgress implements ScriptProgress
{
    /** @var list<ScriptStepResult> */
    public array $executed = [];
    public int $asked = 0;

    public function __construct(private readonly ?int $stopAfter = null)
    {
    }

    public function shouldStop(): bool
    {
        ++$this->asked;

        return null !== $this->stopAfter && \count($this->executed) >= $this->stopAfter;
    }

    public function executed(ScriptStepResult $step): void
    {
        $this->executed[] = $step;
    }
}

<?php

declare(strict_types=1);

namespace App\ScriptRun;

use App\Core\Query\Script\ScriptProgress;
use App\Core\Query\Script\ScriptStepResult;
use App\Entity\ScriptRun;
use App\Repository\ScriptRunRepository;
use Psr\Clock\ClockInterface;

/**
 * L'observateur du worker : écrit dans la base interne où en est l'exécution et lit la demande d'annulation à CHAQUE question
 * (une autre requête la pose, l'objet en mémoire ne la verrait jamais). Chaque appel est aussi un signe de vie.
 */
final class ScriptRunProgress implements ScriptProgress
{
    public function __construct(
        private readonly ScriptRun $run,
        private readonly ScriptRunRepository $runs,
        private readonly ClockInterface $clock,
        /** Point d'observation des tests : appelé après chaque instruction, avant qu'elle soit notée. */
        private readonly ?\Closure $afterStatement = null,
    ) {
    }

    public function shouldStop(): bool
    {
        $this->run->beat($this->now());
        $this->runs->save($this->run);

        return $this->runs->isCancelRequested($this->run->getId());
    }

    public function executed(ScriptStepResult $step): void
    {
        if (null !== $this->afterStatement) {
            ($this->afterStatement)($step);
        }
        $this->run->recordStatement(
            $this->run->getCompleted() + 1,
            ScriptRunReport::statementStatus($step),
            $step->affectedRows ?? $step->returnedRows,
            $step->durationMs,
            ScriptRunReport::sqlstate($step),
            $this->now(),
        );
        $this->runs->save($this->run);
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}

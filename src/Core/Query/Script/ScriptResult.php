<?php

declare(strict_types=1);

namespace App\Core\Query\Script;

/**
 * Le compte rendu : une ligne par instruction (appliquée, annulée, en échec, non lancée), et la cause de l'arrêt
 * (`engine_error` ou `timeout`) quand il y en a une.
 */
final readonly class ScriptResult
{
    /**
     * @param list<ScriptStepResult> $steps
     */
    public function __construct(
        public ScriptStatus $status,
        public bool $dryRun,
        public array $steps,
        public ?string $failureReason,
    ) {
    }

    public function failedStep(): ?ScriptStepResult
    {
        foreach ($this->steps as $step) {
            if (ScriptStepStatus::Failed === $step->status) {
                return $step;
            }
        }

        return null;
    }

    /**
     * @return list<ScriptStepResult>
     */
    public function applied(): array
    {
        return array_values(array_filter($this->steps, static fn (ScriptStepResult $s): bool => ScriptStepStatus::Applied === $s->status));
    }
}

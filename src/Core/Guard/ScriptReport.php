<?php

declare(strict_types=1);

namespace App\Core\Guard;

/**
 * L'aperçu d'un script avant toute exécution : chaque instruction jugée, et ce qu'il faut pour partir.
 */
final readonly class ScriptReport
{
    public ?ScriptStep $firstRefused;

    /**
     * @param list<ScriptStep> $steps
     */
    public function __construct(public array $steps)
    {
        $refused = null;
        foreach ($steps as $step) {
            if (Decision::Refused === $step->verdict->decision) {
                $refused = $step;
                break;
            }
        }
        $this->firstRefused = $refused;
    }

    /**
     * Le script n'est exécutable que s'il n'est pas vide et qu'AUCUNE instruction n'est refusée : un refus arrête tout
     * avant la première exécution.
     */
    public function canRun(): bool
    {
        return [] !== $this->steps && null === $this->firstRefused;
    }

    /**
     * Une seule confirmation couvre l'ensemble du script.
     */
    public function needsConfirmation(): bool
    {
        return $this->destructiveCount() > 0;
    }

    public function destructiveCount(): int
    {
        return \count(array_filter($this->steps, static fn (ScriptStep $step): bool => Decision::NeedsConfirmation === $step->verdict->decision));
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Query\Script;

/**
 * `returnedRows` : lignes d'une lecture (comptées jusqu'à 1000 seulement, rien n'est gardé) ; `affectedRows` : lignes
 * touchées par une écriture. `error` n'existe que pour l'instruction en échec.
 */
final readonly class ScriptStepResult
{
    public function __construct(
        public int $index,
        public string $sql,
        public ScriptStepStatus $status,
        public ?int $affectedRows = null,
        public ?int $returnedRows = null,
        public ?string $error = null,
        public int $durationMs = 0,
    ) {
    }

    public function with(ScriptStepStatus $status): self
    {
        return new self($this->index, $this->sql, $status, $this->affectedRows, $this->returnedRows, $this->error, $this->durationMs);
    }
}

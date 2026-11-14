<?php

declare(strict_types=1);

namespace App\Core\Query\Script;

/**
 * `returnedRows` : lignes d'une lecture (au plus 1000, rien n'est gardé ; `truncated` dit qu'il y en avait davantage) ; `affectedRows` : lignes
 * touchées par une écriture. `error` (le texte du moteur, qui peut citer une valeur) et `errorCode` (SQLSTATE, suivi du code du moteur
 * quand il y en a un : « 23000/1062 ») n'existent que pour l'instruction en échec ; seul le code va dans l'audit.
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
        public bool $truncated = false,
        public ?string $errorCode = null,
    ) {
    }

    public function with(ScriptStepStatus $status): self
    {
        return new self($this->index, $this->sql, $status, $this->affectedRows, $this->returnedRows, $this->error, $this->durationMs, $this->truncated, $this->errorCode);
    }
}

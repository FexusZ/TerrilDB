<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

/**
 * Un avertissement du linter : informatif, il n'empêche jamais l'exécution. `suggestion` est un conseil de réécriture
 * en texte, jamais une instruction que TerrilDB lancerait.
 */
final readonly class LintFinding
{
    /**
     * @param list<string> $columns colonnes en cause (nom seul, tel qu'écrit)
     */
    public function __construct(
        public LintRule $rule,
        public Severity $severity,
        public string $message,
        public ?string $suggestion = null,
        public array $columns = [],
    ) {
    }
}

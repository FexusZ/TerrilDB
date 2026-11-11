<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

/**
 * Un constat de l'audit : une règle, la table concernée, et le détail. `detail` précise le constat quand le nom des colonnes ne suffit pas (types en cause). `suggestion` est une instruction SQL à relire
 * puis lancer soi-même : TerrilDB ne modifie jamais une base cible de sa propre initiative.
 */
final readonly class Finding
{
    /**
     * @param list<string> $columns
     */
    public function __construct(
        public FindingRule $rule,
        public Severity $severity,
        public string $table,
        public array $columns = [],
        public ?string $index = null,
        public ?string $coveredBy = null,
        public ?string $suggestion = null,
        public ?string $detail = null,
    ) {
    }
}

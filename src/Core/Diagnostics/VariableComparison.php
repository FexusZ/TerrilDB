<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * L'écart de configuration entre deux serveurs. `unavailableSide` (`a` ou `b`) dit laquelle des deux sources n'a pas pu être lue ;
 * `masked` compte les variables écartées parce que leur valeur est cachée d'un côté au moins ; `truncated` dit que la liste n'est
 * pas complète (plus de variables modifiées que la limite).
 */
final readonly class VariableComparison
{
    /**
     * @param list<VariableDifference> $differences classées par nom, à l'octet
     */
    public function __construct(
        public DiagnosticStatus $status,
        public ?string $unavailableSide,
        public array $differences,
        public int $masked,
        public bool $truncated,
    ) {
    }
}

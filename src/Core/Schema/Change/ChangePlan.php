<?php

declare(strict_types=1);

namespace App\Core\Schema\Change;

/**
 * Ce qu'il faudrait exécuter pour passer d'une table à l'autre. `transactional` : le moteur annule tout en cas d'échec.
 */
final readonly class ChangePlan
{
    /**
     * @param list<DdlStatement> $statements
     * @param list<PlanLoss>     $losses     ce que le plan fait perdre au-delà de son SQL (historique, valeurs d'une liste), à lister avant de confirmer
     */
    public function __construct(
        public array $statements,
        public bool $transactional,
        public array $losses = [],
    ) {
    }

    /** Au moins une instruction détruit des données ou une structure : l'interface demande une confirmation. */
    public function hasDestructive(): bool
    {
        foreach ($this->statements as $statement) {
            if ($statement->destructive) {
                return true;
            }
        }

        return false;
    }
}

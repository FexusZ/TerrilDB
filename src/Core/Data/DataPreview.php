<?php

declare(strict_types=1);

namespace App\Core\Data;

/**
 * Le plan et, pour chaque instruction, combien de lignes sa clause WHERE retrouve aujourd'hui (nul pour un ajout). Il en faut
 * une pour une modification ou une suppression : `ok` le dit.
 */
final readonly class DataPreview
{
    /**
     * @param list<?int>        $matches
     * @param list<DataWarning> $warnings
     */
    public function __construct(public DataPlan $plan, public array $matches, public array $warnings = [])
    {
    }

    public function ok(): bool
    {
        foreach ($this->matches as $matched) {
            if (null !== $matched && 1 !== $matched) {
                return false;
            }
        }

        return true;
    }
}

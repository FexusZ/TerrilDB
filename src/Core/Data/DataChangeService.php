<?php

declare(strict_types=1);

namespace App\Core\Data;

use App\Core\Data\Exception\DataChangeConflictException;
use App\Core\Data\Exception\DataChangeFailedException;
use App\Core\Data\Exception\DataChangeNeedsConfirmationException;
use App\Core\Data\Exception\InvalidDataChangeException;
use App\Core\Driver\TargetConnection;
use App\Core\Schema\Exception\TableNotFoundException;
use App\Core\Schema\TableDefinition;

/**
 * Point d'entrée de l'édition de données : relit la table réelle, construit les instructions du brouillon, les montre
 * (`preview`) ou les applique (`apply`). Rien ne part si une ligne n'est plus retrouvée de façon unique, ni sans confirmation
 * quand des lignes sont supprimées.
 */
final class DataChangeService
{
    public function __construct(private readonly DataChangePlanner $planner = new DataChangePlanner())
    {
    }

    /**
     * @throws InvalidDataChangeException
     * @throws TableNotFoundException
     * @throws DataChangeFailedException
     */
    public function preview(TargetConnection $target, string $table, DataDraft $draft): DataPreview
    {
        $definition = $target->introspector()->describeTable($table);
        $plan = $this->planner->plan($target->engine, $definition, $draft);

        return new DataPreview($plan, $this->matches($target, $plan), self::warnings($definition, $plan));
    }

    /**
     * @return list<DataWarning>
     */
    private static function warnings(TableDefinition $table, DataPlan $plan): array
    {
        if ([] === $plan->statements) {
            return [];
        }
        $warnings = [];
        if (false === $table->transactional) {
            $warnings[] = new DataWarning('not_transactional', $table->storageEngine);
        }
        if (null !== $table->triggerCount && $table->triggerCount > 0) {
            $warnings[] = new DataWarning('triggers', $table->triggerCount);
        }

        return $warnings;
    }

    /**
     * @throws InvalidDataChangeException
     * @throws TableNotFoundException
     * @throws DataChangeConflictException
     * @throws DataChangeNeedsConfirmationException
     * @throws DataChangeFailedException
     */
    public function apply(TargetConnection $target, string $table, DataDraft $draft, bool $confirmed): DataPlan
    {
        $plan = $this->planner->plan($target->engine, $target->introspector()->describeTable($table), $draft);
        if ($plan->hasDestructive() && !$confirmed) {
            throw new DataChangeNeedsConfirmationException($plan);
        }
        foreach ($this->matches($target, $plan) as $index => $matched) {
            if (null !== $matched && 1 !== $matched) {
                throw new DataChangeConflictException($index, $matched);
            }
        }

        $target->data()->execute($plan);

        return $plan;
    }

    /**
     * @return list<?int>
     */
    private function matches(TargetConnection $target, DataPlan $plan): array
    {
        $matches = [];
        foreach ($plan->statements as $statement) {
            $matches[] = null === $statement->matchSql ? null : $target->data()->matching($statement);
        }

        return $matches;
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Schema\Change;

use App\Core\Driver\TargetConnection;
use App\Core\Schema\Change\Exception\BigTableNeedsConfirmationException;
use App\Core\Schema\Change\Exception\InvalidSchemaChangeException;
use App\Core\Schema\Change\Exception\SchemaChangeFailedException;
use App\Core\Schema\Change\Exception\SchemaChangeNeedsConfirmationException;
use App\Core\Schema\Change\Exception\StaleSchemaException;
use App\Core\Schema\Exception\TableNotFoundException;
use App\Core\Schema\TableDefinition;

/**
 * Point d'entrée unique de l'édition de structure (web, puis le reste) : lit la table réelle, la compare au brouillon, montre
 * (`preview`) ou applique (`apply`). Un brouillon nul veut dire « supprimer la table ». Jamais d'écriture sans empreinte à jour,
 * et jamais d'instruction destructrice sans confirmation.
 */
final class SchemaChangeService
{
    public function __construct(
        private readonly SchemaChangePlanner $planner = new SchemaChangePlanner(),
        private readonly BigTablePolicy $bigTables = new BigTablePolicy(),
    )
    {
    }

    /**
     * @throws InvalidSchemaChangeException
     * @throws TableNotFoundException
     */
    public function preview(TargetConnection $target, string $table, ?TableDraft $draft, ?string $fingerprint): ChangePreview
    {
        $current = $target->introspector()->describeTable($table);
        $actual = SchemaChangePlanner::fingerprint($current);

        $plan = $this->plan($target, $current, $draft);

        return new ChangePreview($plan, $actual, null !== $fingerprint && $fingerprint !== $actual, $this->assess($plan, $current));
    }

    /**
     * Rend le plan exécuté et l'empreinte de la table APRÈS (nulle si elle est supprimée).
     *
     * @return array{ChangePlan, ?string}
     *
     * @throws InvalidSchemaChangeException
     * @throws StaleSchemaException
     * @throws SchemaChangeNeedsConfirmationException
     * @throws BigTableNeedsConfirmationException
     * @throws SchemaChangeFailedException
     * @throws TableNotFoundException
     */
    public function apply(TargetConnection $target, string $table, ?TableDraft $draft, string $fingerprint, bool $confirmed, ?string $confirmedTableName = null): array
    {
        $current = $target->introspector()->describeTable($table);
        if (SchemaChangePlanner::fingerprint($current) !== $fingerprint) {
            throw new StaleSchemaException('The table changed since it was loaded.');
        }
        $plan = $this->plan($target, $current, $draft);
        if ([] === $plan->statements) {
            return [$plan, $fingerprint];
        }
        $big = $this->assess($plan, $current);
        if (BigTableLevel::ConfirmName === $big->level && $confirmedTableName !== $table) {
            throw new BigTableNeedsConfirmationException($big);
        }
        if ($plan->hasDestructive() && !$confirmed) {
            throw new SchemaChangeNeedsConfirmationException($plan);
        }

        $target->ddl()->execute($plan->statements, $plan->transactional);

        return [$plan, null === $draft ? null : SchemaChangePlanner::fingerprint($target->introspector()->describeTable($draft->name))];
    }

    /** Une table grosse n'inquiète que si quelque chose va réellement être exécuté. */
    private function assess(ChangePlan $plan, TableDefinition $current): BigTableAssessment
    {
        $assessment = $this->bigTables->assess($current->estimatedRows);

        return [] === $plan->statements ? new BigTableAssessment(BigTableLevel::None, $assessment->estimatedRows, $assessment->warnAt, $assessment->confirmAt) : $assessment;
    }

    private function plan(TargetConnection $target, TableDefinition $current, ?TableDraft $draft): ChangePlan
    {
        return null === $draft ? $this->planner->planDrop($target->engine, $current) : $this->planner->plan($target->engine, $current, $draft);
    }
}

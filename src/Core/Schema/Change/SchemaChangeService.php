<?php

declare(strict_types=1);

namespace App\Core\Schema\Change;

use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\TableHolders;
use App\Core\Driver\Exception\UnsupportedOperationException;
use App\Core\Driver\LockConflict;
use App\Core\Driver\TargetConnection;
use App\Core\Schema\Change\Exception\InvalidSchemaChangeException;
use App\Core\Schema\Change\Exception\SchemaChangeFailedException;
use App\Core\Schema\Change\Exception\SchemaChangeNeedsConfirmationException;
use App\Core\Schema\Change\Exception\StaleSchemaException;
use App\Core\Schema\Exception\TableNotFoundException;

/**
 * Point d'entrée unique de l'édition de structure (web, puis le reste) : lit la table réelle, la compare au brouillon, montre
 * (`preview`) ou applique (`apply`). Un brouillon nul veut dire « supprimer la table ». Jamais d'écriture sans empreinte à jour,
 * et jamais d'instruction destructrice sans confirmation.
 */
final class SchemaChangeService
{
    public function __construct(private readonly SchemaChangePlanner $planner = new SchemaChangePlanner())
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

        return new ChangePreview($plan, $actual, null !== $fingerprint && $fingerprint !== $actual, [] === $plan->statements ? null : self::holders($target, $table));
    }

    /**
     * Rend le plan exécuté et l'empreinte de la table APRÈS (nulle si elle est supprimée).
     *
     * @return array{ChangePlan, ?string}
     *
     * @throws InvalidSchemaChangeException
     * @throws StaleSchemaException
     * @throws SchemaChangeNeedsConfirmationException
     * @throws SchemaChangeFailedException
     * @throws TableNotFoundException
     */
    public function apply(TargetConnection $target, string $table, ?TableDraft $draft, string $fingerprint, bool $confirmed): array
    {
        $current = $target->introspector()->describeTable($table);
        if (SchemaChangePlanner::fingerprint($current) !== $fingerprint) {
            throw new StaleSchemaException('The table changed since it was loaded.');
        }
        $plan = $this->plan($target, $current, $draft);
        if ([] === $plan->statements) {
            return [$plan, $fingerprint];
        }
        if ($plan->hasDestructive() && !$confirmed) {
            throw new SchemaChangeNeedsConfirmationException($plan);
        }

        try {
            $target->ddl()->execute($plan->statements, $plan->transactional);
        } catch (SchemaChangeFailedException $e) {
            $holders = LockConflict::LockTimeout === $e->lock ? self::holders($target, $table) : null;

            throw null === $holders ? $e : $e->withHolders($holders);
        }

        return [$plan, null === $draft ? null : SchemaChangePlanner::fingerprint($target->introspector()->describeTable($draft->name))];
    }

    /**
     * Qui tient la table, pour avertir. Lecture seule et facultative : un moteur sans diagnostic, un droit manquant ou une panne
     * de lecture rendent « non lisible » (nul, ou un état), jamais une erreur qui gênerait l'aperçu ou masquerait l'échec réel.
     * Cette lecture n'écrit pas de ligne `diagnostic.read` (AuditedDiagnostics) : le cœur ne connaît pas le contexte d'audit, et
     * l'aperçu comme l'échec sont déjà journalisés par AuditedSchemaChanges, pour le propriétaire de la connexion seulement.
     */
    private static function holders(TargetConnection $target, string $table): ?TableHolders
    {
        try {
            return $target->diagnostics()->locks()->tableHolders($table);
        } catch (UnsupportedOperationException) {
            return null;
        } catch (\Throwable) {
            return TableHolders::unreadable(DiagnosticStatus::unsupported('lock_check_failed'));
        }
    }

    private function plan(TargetConnection $target, \App\Core\Schema\TableDefinition $current, ?TableDraft $draft): ChangePlan
    {
        return null === $draft ? $this->planner->planDrop($target->engine, $current) : $this->planner->plan($target->engine, $current, $draft);
    }
}

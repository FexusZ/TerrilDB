<?php

declare(strict_types=1);

namespace App\Audit;

use App\Core\Data\DataChangeService;
use App\Core\Data\DataDraft;
use App\Core\Data\DataPlan;
use App\Core\Data\DataPreview;
use App\Core\Data\DataStatement;
use App\Core\Data\Exception\DataChangeConflictException;
use App\Core\Data\Exception\DataChangeFailedException;
use App\Core\Driver\TargetConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Applique un changement de données et en garde la trace : le nombre de lignes par opération, les colonnes touchées et le SQL AVEC
 * SES PARAMÈTRES (jamais les valeurs : une colonne secrète ne doit pas se retrouver recopiée dans le journal). Le message du moteur
 * n'est pas gardé non plus : il cite parfois la valeur refusée. Un aperçu n'écrit rien et n'est pas journalisé ; une demande de
 * confirmation non plus.
 */
final readonly class AuditedDataChanges
{
    private const MAX_SQL_LENGTH = 4000;

    public function __construct(
        private DataChangeService $changes,
        private AuditLog $audit,
    ) {
    }

    public function preview(TargetConnection $target, string $table, DataDraft $draft): DataPreview
    {
        return $this->changes->preview($target, $table, $draft);
    }

    public function apply(AuditContext $context, Uuid $connectionId, TargetConnection $target, string $table, DataDraft $draft, bool $confirmed): DataPlan
    {
        try {
            $plan = $this->changes->apply($target, $table, $draft, $confirmed);
        } catch (DataChangeFailedException|DataChangeConflictException $e) {
            $this->audit->record($context, AuditAction::DataChangeFailed, AuditOutcome::Failed, $connectionId, [
                'table' => $table,
                'engine' => $target->engine->value,
                'error' => $e instanceof DataChangeFailedException ? 'engine_refused' : 'row_changed',
            ]);

            throw $e;
        }

        $this->audit->record($context, AuditAction::DataChanged, AuditOutcome::Success, $connectionId, [
            'table' => $table,
            'engine' => $target->engine->value,
            'updates' => self::count($plan, 'update'),
            'inserts' => self::count($plan, 'insert'),
            'deletes' => self::count($plan, 'delete'),
            'columns' => self::columns($draft),
            'sql' => mb_substr(implode(";\n", array_map(static fn (DataStatement $s): string => $s->sql, $plan->statements)), 0, self::MAX_SQL_LENGTH),
            'asynchronous' => $plan->asynchronous,
            'confirmed' => $confirmed,
        ]);

        return $plan;
    }

    /**
     * @return list<string>
     */
    private static function columns(DataDraft $draft): array
    {
        $names = [];
        foreach ($draft->updates as $update) {
            array_push($names, ...array_map('strval', array_keys($update->changes)));
        }
        foreach ($draft->inserts as $insert) {
            array_push($names, ...array_map('strval', array_keys($insert->values)));
        }

        return array_values(array_unique($names));
    }

    private static function count(DataPlan $plan, string $operation): int
    {
        return \count(array_filter($plan->statements, static fn (DataStatement $s): bool => $s->operation === $operation));
    }
}

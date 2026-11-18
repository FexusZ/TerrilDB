<?php

declare(strict_types=1);

namespace App\ScriptRun;

use App\Core\Query\Script\ScriptResult;
use App\Core\Query\Script\ScriptStatus;
use App\Core\Query\Script\ScriptStepResult;
use App\Entity\ScriptRunStatus;

/**
 * Du compte rendu du cœur (`ScriptResult`) au suivi (`ScriptRun`) : le statut final et une ligne par instruction SANS texte ni
 * message du moteur (le code SQLSTATE seulement).
 */
final class ScriptRunReport
{
    public static function status(ScriptResult $result): ScriptRunStatus
    {
        if ('cancelled' === $result->failureReason) {
            return ScriptRunStatus::Cancelled;
        }

        return match ($result->status) {
            ScriptStatus::Committed => ScriptRunStatus::Committed,
            ScriptStatus::DryRun => ScriptRunStatus::DryRun,
            ScriptStatus::RolledBack => ScriptRunStatus::RolledBack,
            ScriptStatus::Partial => ScriptRunStatus::Partial,
            ScriptStatus::Failed => ScriptRunStatus::Failed,
        };
    }

    /**
     * @return list<array{rank: int, status: string, affectedRows: int|null, durationMs: int, sqlstate: string|null}>
     */
    public static function statements(ScriptResult $result): array
    {
        $statements = [];
        foreach ($result->steps as $position => $step) {
            $statements[] = [
                'rank' => $position + 1,
                'status' => self::statementStatus($step),
                'affectedRows' => $step->affectedRows ?? $step->returnedRows,
                'durationMs' => $step->durationMs,
                'sqlstate' => self::sqlstate($step),
            ];
        }

        return $statements;
    }

    public static function statementStatus(ScriptStepResult $step): string
    {
        return $step->status->value;
    }

    /**
     * « 23505 » ou « 23000/1062 » -> « 23505 » / « 23000 » : le code du moteur ne sert qu'à l'audit des scripts.
     */
    public static function sqlstate(ScriptStepResult $step): ?string
    {
        return null !== $step->errorCode && 1 === preg_match('/^[0-9A-Z]{5}/', $step->errorCode, $m) ? $m[0] : null;
    }
}

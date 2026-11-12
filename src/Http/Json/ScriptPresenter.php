<?php

declare(strict_types=1);

namespace App\Http\Json;

use App\Core\Guard\Decision;
use App\Core\Guard\ScriptStep;
use App\Core\Query\Script\ScriptPreview;
use App\Core\Query\Script\ScriptResult;
use App\Core\Query\Script\ScriptStepResult;
use App\Core\Query\Script\ScriptStepStatus;

/**
 * La forme JSON d'un script, partagée par l'interface, l'API et le MCP.
 */
final class ScriptPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function preview(ScriptPreview $preview): array
    {
        $report = $preview->report;
        $capabilities = $preview->capabilities;

        return [
            'steps' => array_map(self::step(...), $report->steps),
            'canRun' => $report->canRun(),
            'needsConfirmation' => $report->needsConfirmation(),
            'destructiveCount' => $report->destructiveCount(),
            'firstRefused' => null === $report->firstRefused ? null : ['index' => $report->firstRefused->index, 'reason' => $report->firstRefused->verdict->reason?->value],
            'transactional' => $capabilities->transactional,
            'atomic' => $capabilities->atomic,
            'dryRun' => ['available' => $capabilities->dryRunAvailable, 'reason' => $capabilities->dryRunUnavailableReason],
            'warnings' => $capabilities->warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function result(ScriptResult $result, string $executionId): array
    {
        $failed = $result->failedStep();

        return [
            'executionId' => $executionId,
            'status' => $result->status->value,
            'dryRun' => $result->dryRun,
            'failureReason' => $result->failureReason,
            'applied' => self::indexes($result, ScriptStepStatus::Applied),
            'failed' => $failed?->index,
            'notRun' => self::indexes($result, ScriptStepStatus::NotRun),
            'steps' => array_map(static fn (ScriptStepResult $step): array => [
                'index' => $step->index,
                'sql' => $step->sql,
                'status' => $step->status->value,
                'affectedRows' => $step->affectedRows,
                'returnedRows' => $step->returnedRows,
                'truncated' => $step->truncated,
                'error' => $step->error,
                'durationMs' => $step->durationMs,
            ], $result->steps),
        ];
    }

    /**
     * @return list<int>
     */
    private static function indexes(ScriptResult $result, ScriptStepStatus $status): array
    {
        return array_values(array_map(static fn (ScriptStepResult $s): int => $s->index, array_filter($result->steps, static fn (ScriptStepResult $s): bool => $s->status === $status)));
    }

    /**
     * @return array<string, mixed>
     */
    private static function step(ScriptStep $step): array
    {
        return [
            'index' => $step->index,
            'sql' => $step->sql,
            'verdict' => match ($step->verdict->decision) {
                Decision::Allowed => 'allowed',
                Decision::NeedsConfirmation => 'needs_confirmation',
                Decision::Refused => 'refused',
            },
            'kind' => null === $step->verdict->kind ? null : strtolower($step->verdict->kind->name),
            'reason' => $step->verdict->reason?->value,
        ];
    }
}

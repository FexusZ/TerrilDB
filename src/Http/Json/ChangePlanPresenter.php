<?php

declare(strict_types=1);

namespace App\Http\Json;

use App\Core\Schema\Change\BigTableAssessment;
use App\Core\Schema\Change\ChangePlan;
use App\Core\Schema\Change\DdlStatement;

/**
 * Un plan de modification de structure tel que l'interface l'affiche : le SQL, ce qu'il fait, sur quoi, et s'il détruit.
 */
final class ChangePlanPresenter
{
    /**
     * @return array{statements: list<array{sql: string, operation: string, subject: string, destructive: bool}>, transactional: bool, destructive: bool}
     */
    public static function plan(ChangePlan $plan): array
    {
        return [
            'statements' => array_map(self::statement(...), $plan->statements),
            'transactional' => $plan->transactional,
            'destructive' => $plan->hasDestructive(),
        ];
    }

    /**
     * @return array{level: string, estimatedRows: ?int, warnAt: int, confirmAt: int}
     */
    public static function bigTable(BigTableAssessment $assessment): array
    {
        return ['level' => $assessment->level->value, 'estimatedRows' => $assessment->estimatedRows, 'warnAt' => $assessment->warnAt, 'confirmAt' => $assessment->confirmAt];
    }

    /**
     * @return array{sql: string, operation: string, subject: string, destructive: bool}
     */
    public static function statement(DdlStatement $statement): array
    {
        return ['sql' => $statement->sql, 'operation' => $statement->operation, 'subject' => $statement->subject, 'destructive' => $statement->destructive];
    }
}

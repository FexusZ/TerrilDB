<?php

declare(strict_types=1);

namespace App\Http\Json;

use App\Core\Data\DataPlan;
use App\Core\Data\DataPreview;
use App\Core\Data\DataStatement;
use App\Core\Data\DataWarning;

/**
 * Un plan de modification de données tel que l'interface l'affiche : le SQL avec les valeurs en clair (jamais exécuté tel quel),
 * ce qu'il fait, et combien de lignes il retrouve aujourd'hui.
 */
final class DataPlanPresenter
{
    /**
     * @return array{statements: list<array{sql: string, operation: string, subject: string, destructive: bool, matched: ?int}>, transactional: bool, asynchronous: bool, destructive: bool}
     */
    public static function plan(DataPlan $plan): array
    {
        return [
            'statements' => array_map(static fn (DataStatement $s): array => self::statement($s, null), $plan->statements),
            'transactional' => $plan->transactional,
            'asynchronous' => $plan->asynchronous,
            'destructive' => $plan->hasDestructive(),
        ];
    }

    /**
     * @return array{statements: list<array{sql: string, operation: string, subject: string, destructive: bool, matched: ?int}>, transactional: bool, asynchronous: bool, destructive: bool, ok: bool, warnings: list<array{code: string, value: string|int|null}>}
     */
    public static function preview(DataPreview $preview): array
    {
        $statements = [];
        foreach ($preview->plan->statements as $index => $statement) {
            $statements[] = self::statement($statement, $preview->matches[$index] ?? null);
        }

        $warnings = array_map(static fn (DataWarning $w): array => ['code' => $w->code, 'value' => $w->value], $preview->warnings);

        return ['statements' => $statements, 'ok' => $preview->ok(), 'warnings' => $warnings] + self::plan($preview->plan);
    }

    /**
     * @return array{sql: string, operation: string, subject: string, destructive: bool, matched: ?int}
     */
    public static function statement(DataStatement $statement, ?int $matched): array
    {
        return ['sql' => $statement->display, 'operation' => $statement->operation, 'subject' => $statement->subject, 'destructive' => $statement->destructive, 'matched' => $matched];
    }
}

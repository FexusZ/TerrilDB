<?php

declare(strict_types=1);

namespace App\Http\Json;

use App\Core\Optimizer\LintFinding;

/**
 * Les conseils du linter tels que les rendent l'interface web, l'API et le MCP.
 */
final class LintPresenter
{
    /**
     * @param list<LintFinding> $findings
     *
     * @return list<array<string, mixed>>
     */
    public static function findings(array $findings): array
    {
        return array_map(static fn (LintFinding $f): array => [
            'rule' => $f->rule->value,
            'severity' => $f->severity->value,
            'message' => $f->message,
            'suggestion' => $f->suggestion,
            'columns' => $f->columns,
        ], $findings);
    }
}

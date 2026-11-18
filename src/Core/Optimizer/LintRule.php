<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

/**
 * Les règles du linter de requête (lecture du TEXTE, avant toute exécution). Les valeurs sont stables : l'interface s'en sert.
 */
enum LintRule: string
{
    case FunctionOnColumn = 'function_on_column';
    case ArithmeticOnColumn = 'arithmetic_on_column';
    case LeadingWildcard = 'leading_wildcard_like';
    case SelectStar = 'select_star';
    case LargeOffset = 'large_offset';
    case NotInSubquery = 'not_in_subquery';
    case RowLock = 'row_lock';
    case RecursiveCte = 'recursive_cte';
    case TypeMismatch = 'type_mismatch';
    case OrOnColumns = 'or_on_columns';
    case DistinctJoin = 'distinct_join';
    case CountStarLarge = 'count_star_large';
    case RandomOrder = 'random_order';
}

<?php

declare(strict_types=1);

namespace App\Core\Guard;

/**
 * Motif d'un refus ou d'une demande de confirmation. Les cas sont stables : l'interface, l'API et le MCP
 * en tirent leur propre message.
 */
enum GuardReason: string
{
    case EmptyStatement = 'empty_statement';
    case MultipleStatements = 'multiple_statements';
    case IncompleteSql = 'incomplete_sql';
    case TooLong = 'too_long';
    case NotReadOnly = 'not_read_only';
    case ForbiddenConstruct = 'forbidden_construct';
    case UnsupportedStatement = 'unsupported_statement';
    case DestructiveWithoutWhere = 'destructive_without_where';
    case DestructiveStatement = 'destructive_statement';
    case WriteNotPermitted = 'write_not_permitted';
}

<?php

declare(strict_types=1);

namespace App\Tests\Core\Schema\Change;

use App\Core\Guard\StatementKind;
use App\Core\Query\QueryOptions;
use App\Core\Query\QueryResult;
use App\Core\Query\QueryRunner;

/**
 * Exécuteur de requêtes inerte pour les tests qui n'en ont pas besoin.
 */
final class NullRunner implements QueryRunner
{
    public function run(string $sql, StatementKind $kind, QueryOptions $options): QueryResult
    {
        return QueryResult::affected(0, 0);
    }
}

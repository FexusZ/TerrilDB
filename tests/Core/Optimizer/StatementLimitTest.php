<?php

declare(strict_types=1);

namespace App\Tests\Core\Optimizer;

use App\Core\Connection\Engine;
use App\Core\Optimizer\StatementLimit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatementLimitTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function statements(): iterable
    {
        yield 'sans limite' => ['SELECT * FROM order_items ORDER BY quantity', false];
        yield 'limit' => ['select * from order_items order by quantity limit 10', true];
        yield 'limit avec offset' => ['SELECT * FROM t ORDER BY a LIMIT 10 OFFSET 5', true];
        yield 'fetch first' => ['SELECT * FROM t ORDER BY a FETCH FIRST 10 ROWS ONLY', true];
        yield 'limite dans une sous-requête' => ['SELECT * FROM (SELECT * FROM t LIMIT 5) x ORDER BY a', true];
        yield 'mot dans une chaîne' => ["SELECT * FROM t WHERE a = 'limit 5' ORDER BY a", false];
        yield 'mot dans un commentaire' => ["SELECT * FROM t /* limit 5 */ ORDER BY a", false];
        yield 'colonne entre guillemets' => ['SELECT "limit" FROM t ORDER BY a', false];
    }

    #[DataProvider('statements')]
    public function testSeesALimitAnywhereInTheStatement(string $sql, bool $limited): void
    {
        self::assertSame($limited, (new StatementLimit())->has(Engine::PostgreSql, $sql));
    }

    public function testReadsTheStatementInTheDialectOfTheEngine(): void
    {
        self::assertTrue((new StatementLimit())->has(Engine::MySql, 'SELECT * FROM t ORDER BY a LIMIT 3'));
    }

    public function testAnIncompleteStatementIsTakenAsLimited(): void
    {
        self::assertTrue((new StatementLimit())->has(Engine::MySql, "SELECT * FROM t WHERE a = 'x"));
    }
}

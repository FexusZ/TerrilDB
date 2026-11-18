<?php

declare(strict_types=1);

namespace App\Tests\Driver\MySql;

use App\Driver\MySql\VitessTimeout;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VitessTimeoutTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function statements(): iterable
    {
        yield 'select' => ['SELECT 1', 'SELECT /*vt+ QUERY_TIMEOUT_MS=1500 */ 1'];
        yield 'minuscules' => ['select * from t', 'select /*vt+ QUERY_TIMEOUT_MS=1500 */ * from t'];
        yield 'update' => ['UPDATE t SET a = 1', 'UPDATE /*vt+ QUERY_TIMEOUT_MS=1500 */ t SET a = 1'];
        yield 'delete' => ['DELETE FROM t', 'DELETE /*vt+ QUERY_TIMEOUT_MS=1500 */ FROM t'];
        yield 'insert' => ['INSERT INTO t VALUES (1)', 'INSERT /*vt+ QUERY_TIMEOUT_MS=1500 */ INTO t VALUES (1)'];
        yield 'espaces en tête' => ["  \n SELECT 1", "  \n SELECT /*vt+ QUERY_TIMEOUT_MS=1500 */ 1"];
        yield 'commentaires en tête' => ['/* a */ -- b'."\n".'# c'."\n".'SELECT 1', '/* a */ -- b'."\n".'# c'."\n".'SELECT /*vt+ QUERY_TIMEOUT_MS=1500 */ 1'];
        yield 'parenthèse' => ['(SELECT 1)', '(SELECT /*vt+ QUERY_TIMEOUT_MS=1500 */ 1)'];
        yield 'SHOW sans effet' => ['SHOW TABLES', 'SHOW TABLES'];
        yield 'WITH sans effet' => ['WITH c AS (SELECT 1) SELECT * FROM c', 'WITH c AS (SELECT 1) SELECT * FROM c'];
        yield 'vide' => ['', ''];
        yield 'commentaire seul' => ['/* x */', '/* x */'];
        yield 'commentaire non fermé' => ['/* x SELECT 1', '/* x SELECT 1'];
    }

    #[DataProvider('statements')]
    public function testTheDirectiveFollowsTheFirstKeyword(string $sql, string $expected): void
    {
        self::assertSame($expected, VitessTimeout::inject($sql, 1500));
    }

    public function testAnExistingDirectiveIsKept(): void
    {
        $sql = 'SELECT /*vt+ QUERY_TIMEOUT_MS=10 */ 1';

        self::assertSame($sql, VitessTimeout::inject($sql, 1500));
    }
}

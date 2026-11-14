<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics;

use App\Core\Diagnostics\OpenTransaction;
use App\Core\Guard\SqlDialect;
use PHPUnit\Framework\TestCase;

final class OpenTransactionTest extends TestCase
{
    public function testKeepsWhatIsGiven(): void
    {
        $transaction = new OpenTransaction('42', 'app', 'idle in transaction', 90, 3, 1, true, 'UPDATE t SET a = b', SqlDialect::PostgreSql);

        self::assertSame('42', $transaction->session);
        self::assertSame('app', $transaction->user);
        self::assertSame(90, $transaction->seconds);
        self::assertSame(3, $transaction->rowsLocked);
        self::assertSame(1, $transaction->rowsModified);
        self::assertTrue($transaction->sleeping);
        self::assertSame('UPDATE t SET a = b', $transaction->query);
    }

    public function testLiteralsAreMaskedOrTheTextIsNotShown(): void
    {
        $masked = new OpenTransaction('1', null, null, 0, null, null, false, "UPDATE u SET pw = 'secret' WHERE id = 7", SqlDialect::MySql);
        $cut = new OpenTransaction('1', null, null, 0, null, null, false, "UPDATE u SET pw = 'sec", SqlDialect::MySql);

        self::assertSame('UPDATE u SET pw = ? WHERE id = ?', $masked->query);
        self::assertFalse($masked->queryRedacted);
        self::assertNull($cut->query);
        self::assertTrue($cut->queryRedacted);
    }

    public function testQueryTextIsShortened(): void
    {
        $transaction = new OpenTransaction('1', null, null, 0, null, null, false, 'SELECT '.str_repeat('x', 500), SqlDialect::PostgreSql);

        self::assertSame(200, mb_strlen((string) $transaction->query));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics;

use App\Core\Diagnostics\OpenTransaction;
use PHPUnit\Framework\TestCase;

final class OpenTransactionTest extends TestCase
{
    public function testKeepsWhatIsGiven(): void
    {
        $transaction = new OpenTransaction('42', 'app', 'idle in transaction', 90, 3, 1, true, 'UPDATE t SET a = 1');

        self::assertSame('42', $transaction->session);
        self::assertSame('app', $transaction->user);
        self::assertSame(90, $transaction->seconds);
        self::assertSame(3, $transaction->rowsLocked);
        self::assertSame(1, $transaction->rowsModified);
        self::assertTrue($transaction->sleeping);
        self::assertSame('UPDATE t SET a = 1', $transaction->query);
    }

    public function testQueryTextIsShortened(): void
    {
        $transaction = new OpenTransaction('1', null, null, 0, null, null, false, 'SELECT '.str_repeat('x', 500));

        self::assertSame(200, mb_strlen((string) $transaction->query));
    }
}

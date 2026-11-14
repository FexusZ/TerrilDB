<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics\Log;

use App\Core\Diagnostics\Log\ErrorLogEntry;
use App\Core\Diagnostics\Log\SlowQuery;
use App\Core\Guard\SqlDialect;
use PHPUnit\Framework\TestCase;

final class LogModelsTest extends TestCase
{
    public function testAnErrorLogLineMasksItsMessageAtConstruction(): void
    {
        $entry = new ErrorLogEntry(1_700_000_000.5, 'Warning', 'Server', 'MY-010055', "Access denied for user 'alice'@'10.0.0.1'");

        self::assertSame('warning', $entry->level);
        self::assertSame('Access denied for user ?@?', $entry->message);
        self::assertSame('MY-010055', $entry->code);
    }

    public function testAnErrorCodeIsAKeywordNotText(): void
    {
        $entry = new ErrorLogEntry(null, 'Note', 'InnoDB', "MY-1'; DROP", 'x');

        self::assertNull($entry->code);
    }

    public function testASlowQueryMasksItsLiteralsAndSaysWhenItCouldNot(): void
    {
        $slow = new SlowQuery(1_700_000_000.0, 'app', 'shop', 1523.4, 0.2, 3, 70000, "SELECT * FROM users WHERE token = 'abc' AND id = 42", SqlDialect::MySql);
        $cut = new SlowQuery(null, null, null, null, null, null, null, "SELECT 'unfinished", SqlDialect::MySql);

        self::assertSame('SELECT * FROM users WHERE token = ? AND id = ?', $slow->query);
        self::assertFalse($slow->queryRedacted);
        self::assertNull($cut->query);
        self::assertTrue($cut->queryRedacted);
    }
}

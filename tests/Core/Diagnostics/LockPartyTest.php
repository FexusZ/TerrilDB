<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics;

use App\Core\Diagnostics\LockParty;
use App\Core\Guard\SqlDialect;
use PHPUnit\Framework\TestCase;

final class LockPartyTest extends TestCase
{
    public function testLiteralsAreMasked(): void
    {
        $party = new LockParty('1', 'app', "ALTER ROLE x PASSWORD 'secret'", SqlDialect::PostgreSql);

        self::assertSame('ALTER ROLE x PASSWORD ?', $party->query);
        self::assertFalse($party->queryRedacted);
    }

    public function testATextThatCannotBeScannedIsNotShown(): void
    {
        $party = new LockParty('1', 'app', "ALTER ROLE x PASSWORD 'sec", SqlDialect::PostgreSql);

        self::assertNull($party->query);
        self::assertTrue($party->queryRedacted);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics;

use App\Core\Diagnostics\ServerSession;
use App\Core\Guard\SqlDialect;
use PHPUnit\Framework\TestCase;

final class ServerSessionTest extends TestCase
{
    public function testKeepsWhatIsGiven(): void
    {
        $session = new ServerSession('42', 'app', '10.0.0.5', 'shop', 'Query', 3, 'SELECT id FROM t', true, SqlDialect::MySql);

        self::assertSame('42', $session->id);
        self::assertSame('app', $session->user);
        self::assertSame('10.0.0.5', $session->client);
        self::assertSame('shop', $session->database);
        self::assertSame('Query', $session->state);
        self::assertSame(3, $session->seconds);
        self::assertSame('SELECT id FROM t', $session->query);
        self::assertTrue($session->current);
    }

    public function testQueryTextIsShortenedByDefault(): void
    {
        $session = new ServerSession('1', null, null, null, null, null, 'SELECT '.str_repeat('x', 500), false, SqlDialect::MySql);

        self::assertNotNull($session->query);
        self::assertSame(ServerSession::MAX_QUERY_LENGTH, mb_strlen($session->query));
        self::assertStringEndsWith('…', $session->query);
    }

    public function testLiteralsOfTheQueryAreMasked(): void
    {
        $session = new ServerSession('1', null, null, null, null, null, "ALTER ROLE x PASSWORD 'secret'", false, SqlDialect::PostgreSql);

        self::assertSame('ALTER ROLE x PASSWORD ?', $session->query);
        self::assertFalse($session->queryRedacted);
    }

    public function testAQueryThatCannotBeScannedIsNotShown(): void
    {
        $session = new ServerSession('1', null, null, null, null, null, "ALTER ROLE x PASSWORD 'sec", false, SqlDialect::PostgreSql);

        self::assertNull($session->query);
        self::assertTrue($session->queryRedacted);
    }

    public function testEmptyQueryIsNone(): void
    {
        self::assertNull((new ServerSession('1', null, null, null, null, null, '', false, SqlDialect::MySql))->query);
    }

    public function testInvalidUtf8IsScrubbed(): void
    {
        $session = new ServerSession('1', null, null, null, null, null, "SELECT '\xff'", false, SqlDialect::MySql);

        self::assertNotNull($session->query);
        self::assertTrue(mb_check_encoding($session->query, 'UTF-8'));
    }

    public function testAnIdentifierIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ServerSession('', null, null, null, null, null, null, false, SqlDialect::MySql);
    }
}

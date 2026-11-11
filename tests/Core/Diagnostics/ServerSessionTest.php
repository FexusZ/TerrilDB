<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics;

use App\Core\Diagnostics\ServerSession;
use PHPUnit\Framework\TestCase;

final class ServerSessionTest extends TestCase
{
    public function testKeepsWhatIsGiven(): void
    {
        $session = new ServerSession('42', 'app', '10.0.0.5', 'shop', 'Query', 3, 'SELECT 1', true);

        self::assertSame('42', $session->id);
        self::assertSame('app', $session->user);
        self::assertSame('10.0.0.5', $session->client);
        self::assertSame('shop', $session->database);
        self::assertSame('Query', $session->state);
        self::assertSame(3, $session->seconds);
        self::assertSame('SELECT 1', $session->query);
        self::assertTrue($session->current);
    }

    public function testQueryTextIsShortenedByDefault(): void
    {
        $session = new ServerSession('1', null, null, null, null, null, 'SELECT '.str_repeat('x', 500), false);

        self::assertNotNull($session->query);
        self::assertSame(ServerSession::MAX_QUERY_LENGTH, mb_strlen($session->query));
        self::assertStringEndsWith('…', $session->query);
    }

    public function testEmptyQueryIsNone(): void
    {
        self::assertNull((new ServerSession('1', null, null, null, null, null, '', false))->query);
    }

    public function testInvalidUtf8IsScrubbed(): void
    {
        $session = new ServerSession('1', null, null, null, null, null, "SELECT '\xff'", false);

        self::assertNotNull($session->query);
        self::assertTrue(mb_check_encoding($session->query, 'UTF-8'));
    }

    public function testAnIdentifierIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ServerSession('', null, null, null, null, null, null, false);
    }
}

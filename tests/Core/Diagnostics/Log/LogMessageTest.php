<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics\Log;

use App\Core\Diagnostics\Log\LogMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LogMessageTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function messages(): iterable
    {
        yield 'quoted strings' => ["Access denied for user 'alice'@'10.1.2.3' (using password: YES)", 'Access denied for user ?@? (using password: YES)'];
        yield 'double quotes and backticks' => ['Table "shop.orders" and `secret_col` are marked as crashed', 'Table ? and ? are marked as crashed'];
        yield 'user at host' => ['Aborted connection 12 to db: unconnected user: bob@db-7.internal.example host: db-7.internal.example', 'Aborted connection 12 to db: unconnected user: ? host: db-7.internal.example'];
        yield 'ipv4' => ['Got an error reading communication packets from 192.168.10.20:55123', 'Got an error reading communication packets from ?'];
        yield 'ipv6' => ['Connection from fe80::1ff:fe23:4567:890a refused', 'Connection from ? refused'];
        yield 'password assignments' => ['Failed with password=hunter2 pwd: abc123 token=xyz', 'Failed with password=? pwd=? token=?'];
        yield 'unterminated quote' => ["Cannot parse 'abc def", 'Cannot parse ?'];
        yield 'plain text is kept' => ['mysqld: ready for connections. Version: 8.4.2', 'mysqld: ready for connections. Version: 8.4.2'];
    }

    #[DataProvider('messages')]
    public function testLiteralsAddressesAndSecretsAreMasked(string $raw, string $expected): void
    {
        self::assertSame($expected, LogMessage::of($raw));
    }

    public function testItIsShortenedAfterTheMasking(): void
    {
        $masked = LogMessage::of(str_repeat('word ', 200));

        self::assertNotNull($masked);
        self::assertSame(LogMessage::MAX_LENGTH, mb_strlen($masked));
        self::assertStringEndsWith('…', $masked);
    }

    public function testInvalidBytesAreScrubbedAndEmptyIsNull(): void
    {
        self::assertSame('ok ?', LogMessage::of("ok \xff"));
        self::assertNull(LogMessage::of(null));
        self::assertNull(LogMessage::of("  \n"));
    }
}

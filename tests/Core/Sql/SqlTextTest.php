<?php

declare(strict_types=1);

namespace App\Tests\Core\Sql;

use App\Core\Connection\Engine;
use App\Core\Sql\SqlText;
use App\Core\Sql\UnsafeSqlNameException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqlTextTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileNames(): iterable
    {
        yield 'LF' => ["x\nDROP DATABASE y"];
        yield 'CR' => ["x\rDROP DATABASE y"];
        yield 'CRLF' => ["x\r\nDROP DATABASE y"];
        yield 'NEL' => ["x\u{85}DROP DATABASE y"];
        yield 'LS' => ["x\u{2028}DROP DATABASE y"];
        yield 'PS' => ["x\u{2029}DROP DATABASE y"];
        yield 'VT' => ["x\x0BDROP"];
        yield 'FF' => ["x\x0CDROP"];
        yield 'NUL' => ["x\0y"];
        yield 'ESC' => ["x\x1By"];
        yield 'DEL' => ["x\x7Fy"];
        yield 'C1' => ["x\u{9B}y"];
        yield 'bidi override' => ["x\u{202E}y"];
        yield 'bidi isolate' => ["x\u{2066}y"];
        yield 'invalid UTF-8' => ["x\xC3\x28y"];
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('a', 1025)];
    }

    #[DataProvider('hostileNames')]
    public function testHostileNamesAreNotSafe(string $name): void
    {
        self::assertFalse(SqlText::isSafeName($name));
    }

    #[DataProvider('hostileNames')]
    public function testHostileNamesAreNeverQuoted(string $name): void
    {
        foreach (Engine::cases() as $engine) {
            try {
                SqlText::quoteIdentifier($engine, $name);
                self::fail('Un nom hostile a été cité pour '.$engine->value);
            } catch (UnsafeSqlNameException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testOrdinaryNamesAreSafe(): void
    {
        foreach (['orders', 'Commandes été', 'a b', "o'brien", 'x*/y', 'x--y', '日本語', '😀', 'a`b', 'a"b', str_repeat('a', 1024), "a\u{200D}b"] as $name) {
            self::assertTrue(SqlText::isSafeName($name), $name);
        }
    }

    public function testQuotingPerDialect(): void
    {
        self::assertSame('"a""b"', SqlText::quoteIdentifier(Engine::PostgreSql, 'a"b'));
        self::assertSame('"a`b"', SqlText::quoteIdentifier(Engine::PostgreSql, 'a`b'));
        self::assertSame('`a``b`', SqlText::quoteIdentifier(Engine::MySql, 'a`b'));
        self::assertSame('`a"b`', SqlText::quoteIdentifier(Engine::MariaDb, 'a"b'));
        self::assertSame('`a\\\\b\\`c`', SqlText::quoteIdentifier(Engine::ClickHouse, 'a\\b`c'));
        self::assertSame('`x*/y`', SqlText::quoteIdentifier(Engine::MySql, 'x*/y'));
    }

    public function testQuotedNameNeverEndsTheIdentifierEarly(): void
    {
        $quoted = SqlText::quoteIdentifier(Engine::MySql, 'a`; DROP TABLE t; -- ');
        self::assertSame('`a``; DROP TABLE t; -- `', $quoted);
        $quoted = SqlText::quoteIdentifier(Engine::PostgreSql, 'a"; DROP TABLE t; -- ');
        self::assertSame('"a""; DROP TABLE t; -- "', $quoted);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function commentInputs(): iterable
    {
        foreach (self::hostileNames() as $label => [$name]) {
            yield $label => [$name];
        }
        yield 'comment end' => ['x*/ DROP TABLE y'];
        yield 'long' => [str_repeat("é\n", 5000)];
    }

    #[DataProvider('commentInputs')]
    public function testCommentSafeStaysOnOneLineAndCannotCloseAComment(string $name): void
    {
        $text = SqlText::commentSafe($name);

        self::assertSame(1, preg_match('/\A[^\p{Cc}\p{Zl}\p{Zp}\x{202A}-\x{202E}\x{2066}-\x{2069}]*\z/u', $text), 'une seule ligne, sans contrôle');
        self::assertStringNotContainsString('*/', $text);
        self::assertLessThanOrEqual(80, mb_strlen($text));
    }

    public function testCommentSafeKeepsReadableNamesAsIs(): void
    {
        self::assertSame('orders', SqlText::commentSafe('orders'));
        self::assertSame('Commandes été', SqlText::commentSafe('Commandes été'));
    }

    public function testCommentSafeMarksWhatItChanged(): void
    {
        self::assertSame("x\u{FFFD}DROP DATABASE y", SqlText::commentSafe("x\nDROP DATABASE y"));
        self::assertSame('x* /y', SqlText::commentSafe('x*/y'));
    }
}

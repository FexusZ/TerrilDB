<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics;

use App\Core\Diagnostics\QueryText;
use App\Core\Guard\SqlDialect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryTextTest extends TestCase
{
    /**
     * @return iterable<string, array{string, SqlDialect, string}>
     */
    public static function masked(): iterable
    {
        yield 'pg role password' => ["ALTER ROLE x PASSWORD 'secret'", SqlDialect::PostgreSql, 'ALTER ROLE x PASSWORD ?'];
        yield 'mysql identified by' => ["CREATE USER u IDENTIFIED BY 'p''w\\'x'", SqlDialect::MySql, 'CREATE USER u IDENTIFIED BY ?'];
        yield 'mysql double quotes are strings' => ['SELECT "secret"', SqlDialect::MySql, 'SELECT ?'];
        yield 'pg double quotes are identifiers' => ['SELECT "Name" FROM t', SqlDialect::PostgreSql, 'SELECT "Name" FROM t'];
        yield 'pg dollar quotes' => ['DO $$ BEGIN PERFORM 1; END $$', SqlDialect::PostgreSql, 'DO ?'];
        yield 'pg tagged dollar quotes' => ['DO $f$ secret $f$ LANGUAGE plpgsql', SqlDialect::PostgreSql, 'DO ? LANGUAGE plpgsql'];
        yield 'pg escape string' => ["SELECT E'a\\'b secret'", SqlDialect::PostgreSql, 'SELECT ?'];
        yield 'numbers' => ['SELECT * FROM t1 WHERE id = 42 AND p > 1.5e3 AND h = 0xFF', SqlDialect::MySql, 'SELECT * FROM t1 WHERE id = ? AND p > ? AND h = ?'];
        yield 'hex and charset prefixes' => ["SELECT X'41', _utf8'secret', N'x'", SqlDialect::MySql, 'SELECT ?, _utf8?, ?'];
        yield 'comments' => ["SELECT 1 /* password 'x' */ -- tail secret\nFROM t # more", SqlDialect::MySql, 'SELECT ?    FROM t  '];
        yield 'clickhouse backslash' => ["SELECT 'a\\'b', `col`", SqlDialect::ClickHouse, 'SELECT ?, `col`'];
        yield 'executable comment code is read' => ["SELECT /*!50000 'secret' */ 1", SqlDialect::MySql, 'SELECT /*!50000 ? */ ?'];
    }

    #[DataProvider('masked')]
    public function testLiteralsAreReplaced(string $sql, SqlDialect $dialect, string $expected): void
    {
        $text = QueryText::of($sql, $dialect);

        self::assertSame($expected, $text->text);
        self::assertFalse($text->redacted);
    }

    /**
     * @return iterable<string, array{string, SqlDialect}>
     */
    public static function unreadable(): iterable
    {
        yield 'cut inside a string' => ["ALTER ROLE x PASSWORD 'sec", SqlDialect::PostgreSql];
        yield 'cut inside a mysql string' => ["CREATE USER u IDENTIFIED BY 'sec", SqlDialect::MySql];
        yield 'cut inside a dollar quote' => ['DO $$ secret', SqlDialect::PostgreSql];
        yield 'cut inside a comment' => ['SELECT 1 /* secret', SqlDialect::MySql];
        yield 'cut inside an identifier' => ['SELECT "abc', SqlDialect::PostgreSql];
    }

    #[DataProvider('unreadable')]
    public function testWhatCannotBeScannedIsNeverShown(string $sql, SqlDialect $dialect): void
    {
        $text = QueryText::of($sql, $dialect);

        self::assertNull($text->text);
        self::assertTrue($text->redacted);
    }

    public function testMaskingComesBeforeTheCut(): void
    {
        $text = QueryText::of('SELECT '.str_repeat('a', 190)." , 'secret value'", SqlDialect::PostgreSql);

        self::assertNotNull($text->text);
        self::assertStringNotContainsString('secret', $text->text);
        self::assertSame(QueryText::MAX_LENGTH, mb_strlen($text->text));
        self::assertStringEndsWith('…', $text->text);
    }

    public function testNothingIsNone(): void
    {
        foreach ([null, '', " \n"] as $empty) {
            $text = QueryText::of($empty, SqlDialect::MySql);
            self::assertNull($text->text);
            self::assertFalse($text->redacted);
        }
    }

    public function testInvalidUtf8IsScrubbed(): void
    {
        $text = QueryText::of("SELECT x\xff FROM t", SqlDialect::MySql);

        self::assertNotNull($text->text);
        self::assertTrue(mb_check_encoding($text->text, 'UTF-8'));
    }
}

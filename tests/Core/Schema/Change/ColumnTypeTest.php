<?php

declare(strict_types=1);

namespace App\Tests\Core\Schema\Change;

use App\Core\Schema\Change\ColumnType;
use App\Core\Schema\Change\Exception\InvalidSchemaChangeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ColumnTypeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function accepted(): iterable
    {
        yield 'simple' => ['integer', 'integer'];
        yield 'length' => ['character varying(20)', 'character varying(20)'];
        yield 'precision' => ['numeric(10, 2)', 'numeric(10, 2)'];
        yield 'unsigned' => ['int unsigned', 'int unsigned'];
        yield 'extra spaces are collapsed' => ["  varchar(  20 )  ", 'varchar(20)'];
        yield 'time zone' => ['timestamp without time zone', 'timestamp without time zone'];
        yield 'array' => ['text[]', 'text[]'];
        yield 'enum values' => ["enum('a','b c','it''s')", "enum('a', 'b c', 'it''s')"];
        yield 'clickhouse nesting' => ['LowCardinality(Nullable(String))', 'LowCardinality(Nullable(String))'];
        yield 'clickhouse datetime64' => ["DateTime64(3, 'UTC')", "DateTime64(3, 'UTC')"];
        yield 'clickhouse enum with values' => ["Enum8('a' = 1, 'b' = 2)", "Enum8('a' = 1, 'b' = 2)"];
        yield 'schema qualified' => ['public.citext', 'public.citext'];
    }

    #[DataProvider('accepted')]
    public function testAcceptsATypeTheEngineWritesAndNormalizesItsSpaces(string $given, string $expected): void
    {
        self::assertSame($expected, ColumnType::validated($given));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'statement separator' => ['int; DROP TABLE users'];
        yield 'unbalanced closing' => ['int) , DROP COLUMN x'];
        yield 'unbalanced opening' => ['varchar(20'];
        yield 'comment' => ['int -- x'];
        yield 'block comment' => ['int /* x */'];
        yield 'unterminated string' => ["enum('a"];
        yield 'backslash' => ["enum('a\\')"];
        yield 'newline in string' => ["enum('a\nb')"];
        yield 'nul' => ["int\0"];
        yield 'constraint smuggled in' => ['int PRIMARY KEY'];
        yield 'default smuggled in' => ['int DEFAULT 5'];
        yield 'not null smuggled in' => ['int NOT NULL'];
        yield 'reference smuggled in' => ['int REFERENCES other(id)'];
        yield 'generated smuggled in' => ['int GENERATED ALWAYS AS IDENTITY'];
        yield 'starts with a digit' => ['4int'];
        yield 'too long' => [str_repeat('a', 201)];
        yield 'quote outside parentheses' => ["int 'x'"];
        yield 'backtick' => ['`int`'];
    }

    #[DataProvider('refused')]
    public function testRefusesAnythingThatIsNotJustAType(string $given): void
    {
        $this->expectException(InvalidSchemaChangeException::class);

        ColumnType::validated($given);
    }
}

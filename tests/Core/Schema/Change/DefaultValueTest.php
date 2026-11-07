<?php

declare(strict_types=1);

namespace App\Tests\Core\Schema\Change;

use App\Core\Schema\Change\DefaultKind;
use App\Core\Schema\Change\DefaultValue;
use App\Core\Schema\Change\Exception\InvalidSchemaChangeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DefaultValueTest extends TestCase
{
    public function testNoTextMeansNoDefault(): void
    {
        self::assertNull(DefaultValue::parse(''));
        self::assertNull(DefaultValue::parse('   '));
    }

    public function testNullIsAnExplicitNullDefault(): void
    {
        $default = DefaultValue::parse('null');

        self::assertSame(DefaultKind::Null, $default?->kind);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function numbers(): iterable
    {
        yield 'integer' => ['0', '0'];
        yield 'negative' => ['-5', '-5'];
        yield 'decimal' => ['12.50', '12.50'];
    }

    #[DataProvider('numbers')]
    public function testNumbersAreKeptAsTheyAre(string $given, string $expected): void
    {
        $default = DefaultValue::parse($given);

        self::assertSame(DefaultKind::Number, $default?->kind);
        self::assertSame($expected, $default->value);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function literals(): iterable
    {
        yield 'quoted' => ["'pending'", 'pending'];
        yield 'quoted with doubled apostrophe' => ["'it''s'", "it's"];
        yield 'quoted with a postgres cast, as the engine reports it' => ["'pending'::character varying", 'pending'];
        yield 'bare text is a string' => ['pending', 'pending'];
        yield 'bare text with an apostrophe' => ["it's", "it's"];
        yield 'bare text that looks like a statement' => ["x'; DROP TABLE users; --", "x'; DROP TABLE users; --"];
        yield 'bare text that looks like a function but is not allowed' => ['lower(name)', 'lower(name)'];
        yield 'backslash' => ['a\\b', 'a\\b'];
    }

    #[DataProvider('literals')]
    public function testAnythingElseIsAStringLiteralNeverRawSql(string $given, string $expected): void
    {
        $default = DefaultValue::parse($given);

        self::assertSame(DefaultKind::Literal, $default?->kind);
        self::assertSame($expected, $default->value);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function expressions(): iterable
    {
        yield 'now' => ['now()', 'now()'];
        yield 'now upper' => ['NOW()', 'now()'];
        yield 'current_timestamp' => ['CURRENT_TIMESTAMP', 'current_timestamp'];
        yield 'current_timestamp precision' => ['current_timestamp(3)', 'current_timestamp(3)'];
        yield 'current_date' => ['current_date', 'current_date'];
        yield 'uuid' => ['uuid()', 'uuid()'];
        yield 'gen_random_uuid' => ['gen_random_uuid()', 'gen_random_uuid()'];
        yield 'true' => ['TRUE', 'true'];
        yield 'false' => ['false', 'false'];
        yield 'clickhouse today' => ['today()', 'today()'];
        yield 'clickhouse uuid' => ['generateUUIDv4()', 'generateuuidv4()'];
        yield 'sequence, as postgres reports it' => ["nextval('orders_id_seq'::regclass)", "nextval('orders_id_seq'::regclass)"];
    }

    #[DataProvider('expressions')]
    public function testASmallListOfFunctionsAndKeywordsIsKeptAsAnExpression(string $given, string $expected): void
    {
        $default = DefaultValue::parse($given);

        self::assertSame(DefaultKind::Expression, $default?->kind);
        self::assertSame($expected, $default->value);
    }

    public function testASequenceNameWithAQuoteOrASpaceIsNotAnExpression(): void
    {
        $default = DefaultValue::parse("nextval('a'' b'::regclass)");

        self::assertSame(DefaultKind::Literal, $default?->kind);
    }

    public function testRefusesANulByteAndAnExcessiveLength(): void
    {
        $this->expectException(InvalidSchemaChangeException::class);
        DefaultValue::parse("a\0b");
    }

    public function testRefusesAnExcessiveLength(): void
    {
        $this->expectException(InvalidSchemaChangeException::class);
        DefaultValue::parse(str_repeat('a', 501));
    }
}

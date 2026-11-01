<?php

declare(strict_types=1);

namespace App\Tests\Core\Query;

use App\Core\Query\CellValue;
use PHPUnit\Framework\TestCase;

final class CellValueTest extends TestCase
{
    public function testScalarsAndNullPassThrough(): void
    {
        self::assertSame(1, CellValue::normalize(1));
        self::assertSame(1.5, CellValue::normalize(1.5));
        self::assertTrue(CellValue::normalize(true));
        self::assertNull(CellValue::normalize(null));
        self::assertSame('é', CellValue::normalize('é'));
        self::assertSame('', CellValue::normalize(''));
    }

    public function testBinaryStringsBecomeHex(): void
    {
        self::assertSame('0xff00fe', CellValue::normalize("\xff\x00\xfe"));
    }

    public function testLongBinaryValuesAreCutAndMarked(): void
    {
        $value = CellValue::normalize(str_repeat("\xff", 1000));

        self::assertIsString($value);
        self::assertStringStartsWith('0x'.str_repeat('ff', 256), $value);
        self::assertStringEndsWith('…', $value);
        self::assertLessThan(600, \strlen($value));
    }

    public function testStreamsAreReadAsBinary(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, "\x01\x02");
        rewind($stream);

        self::assertSame('0x0102', CellValue::normalize($stream));
    }

    public function testUnexpectedTypesAreNamedNotLeaked(): void
    {
        self::assertSame('[unsupported: array]', CellValue::normalize(['secret']));
        self::assertSame('[unsupported: stdClass]', CellValue::normalize(new \stdClass()));
    }
}

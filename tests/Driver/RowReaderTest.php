<?php

declare(strict_types=1);

namespace App\Tests\Driver;

use App\Driver\RowReader;
use PHPUnit\Framework\TestCase;

final class RowReaderTest extends TestCase
{
    public function testReadsStringsFromScalars(): void
    {
        $row = new RowReader(['a' => 'text', 'b' => 12, 'c' => 1.5]);

        self::assertSame('text', $row->string('a'));
        self::assertSame('12', $row->string('b'));
        self::assertSame('1.5', $row->string('c'));
    }

    public function testNullableStringKeepsNullAndTurnsEmptyIntoNull(): void
    {
        $row = new RowReader(['n' => null, 'e' => '', 's' => 'x']);

        self::assertNull($row->nullableString('n'));
        self::assertNull($row->nullableString('e'));
        self::assertSame('x', $row->nullableString('s'));
    }

    public function testReadsIntegersFromNumericValues(): void
    {
        $row = new RowReader(['i' => 7, 's' => '42', 'n' => null]);

        self::assertSame(7, $row->int('i'));
        self::assertSame(42, $row->int('s'));
        self::assertNull($row->nullableInt('n'));
        self::assertSame(42, $row->nullableInt('s'));
    }

    public function testReadsBooleansFromDatabaseSpellings(): void
    {
        $row = new RowReader(['t1' => true, 't2' => 1, 't3' => '1', 't4' => 'YES', 't5' => 't', 'f1' => false, 'f2' => 0, 'f3' => '0', 'f4' => 'NO', 'f5' => 'f']);

        foreach (['t1', 't2', 't3', 't4', 't5'] as $key) {
            self::assertTrue($row->bool($key), $key);
        }
        foreach (['f1', 'f2', 'f3', 'f4', 'f5'] as $key) {
            self::assertFalse($row->bool($key), $key);
        }
    }

    public function testAMissingOrUnusableValueIsAnError(): void
    {
        $row = new RowReader(['n' => null, 'a' => [1], 'x' => 'abc']);

        foreach ([
            static fn () => $row->string('missing'),
            static fn () => $row->string('n'),
            static fn () => $row->string('a'),
            static fn () => $row->int('x'),
            static fn () => $row->bool('x'),
        ] as $read) {
            try {
                $read();
                self::fail('Expected an error.');
            } catch (\UnexpectedValueException) {
            }
        }
        $this->addToAssertionCount(1);
    }
}

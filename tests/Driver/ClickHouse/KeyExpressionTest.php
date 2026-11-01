<?php

declare(strict_types=1);

namespace App\Tests\Driver\ClickHouse;

use App\Driver\ClickHouse\KeyExpression;
use PHPUnit\Framework\TestCase;

final class KeyExpressionTest extends TestCase
{
    public function testSplitsAPlainColumnList(): void
    {
        self::assertSame(['customer_id', 'ordered_at'], KeyExpression::split('customer_id, ordered_at'));
    }

    public function testKeepsCallsWithSeveralArgumentsWhole(): void
    {
        self::assertSame(['toDate(ts)', 'cityHash64(a, b)', 'id'], KeyExpression::split('toDate(ts), cityHash64(a, b), id'));
    }

    public function testCommasInStringsDoNotSplit(): void
    {
        self::assertSame(["concat(a, ', ', b)", 'id'], KeyExpression::split("concat(a, ', ', b), id"));
    }

    public function testAnEmptyKeyHasNoColumns(): void
    {
        self::assertSame([], KeyExpression::split(''));
        self::assertSame([], KeyExpression::split('  '));
    }

    public function testASingleParenthesizedTupleIsUnwrapped(): void
    {
        self::assertSame(['a', 'b'], KeyExpression::split('(a, b)'));
    }
}

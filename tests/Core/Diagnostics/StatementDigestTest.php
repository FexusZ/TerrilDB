<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics;

use App\Core\Diagnostics\StatementDigest;
use PHPUnit\Framework\TestCase;

final class StatementDigestTest extends TestCase
{
    private function digest(?int $examined, ?int $returned, string $text = 'SELECT * FROM `t` WHERE `a` = ?'): StatementDigest
    {
        return new StatementDigest($text, 'shop', 10, 1500.0, 150.0, 400.0, $examined, $returned, null, null, null, null, null);
    }

    public function testTheRatioIsExaminedOverReturned(): void
    {
        self::assertSame(1000.0, $this->digest(10000, 10)->ratio());
        self::assertSame(2.5, $this->digest(5, 2)->ratio());
    }

    public function testNothingReturnedDividesByOne(): void
    {
        self::assertSame(300.0, $this->digest(300, 0)->ratio());
    }

    public function testTheRatioIsUnknownWithoutRowsExamined(): void
    {
        self::assertNull($this->digest(null, 10)->ratio());
        self::assertNull($this->digest(null, null)->ratio());
    }

    public function testTheTextIsShortenedLikeAnyDiagnosticText(): void
    {
        $text = $this->digest(null, null, 'SELECT '.str_repeat('x', 500))->text;

        self::assertNotNull($text);
        self::assertSame(200, mb_strlen($text));
    }

    public function testACallIsAtLeastOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new StatementDigest('SELECT 1', null, 0, 0.0, 0.0, null, null, null, null, null, null, null, null);
    }
}

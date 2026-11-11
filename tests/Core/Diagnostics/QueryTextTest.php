<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics;

use App\Core\Diagnostics\QueryText;
use PHPUnit\Framework\TestCase;

final class QueryTextTest extends TestCase
{
    public function testShortensToTheLimitWithAnEllipsis(): void
    {
        $text = QueryText::shorten('SELECT '.str_repeat('x', 500));

        self::assertNotNull($text);
        self::assertSame(QueryText::MAX_LENGTH, mb_strlen($text));
        self::assertStringEndsWith('…', $text);
    }

    public function testEmptyOrMissingIsNone(): void
    {
        self::assertNull(QueryText::shorten(null));
        self::assertNull(QueryText::shorten(" \n"));
    }

    public function testInvalidUtf8IsScrubbed(): void
    {
        self::assertTrue(mb_check_encoding((string) QueryText::shorten("SELECT '\xff'"), 'UTF-8'));
    }
}

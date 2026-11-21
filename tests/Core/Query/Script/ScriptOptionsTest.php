<?php

declare(strict_types=1);

namespace App\Tests\Core\Query\Script;

use App\Core\Query\Script\ScriptOptions;
use App\Core\Query\WritePermissions;
use PHPUnit\Framework\TestCase;

final class ScriptOptionsTest extends TestCase
{
    public function testTheDefaultDelayEndsBeforeTheProxyCutsTheRequestAt120Seconds(): void
    {
        self::assertLessThanOrEqual(100_000, (new ScriptOptions(WritePermissions::all()))->timeoutMs);
    }

    public function testADetachedRunMayLastHoursButNotMoreThanADay(): void
    {
        self::assertSame(86_400_000, (new ScriptOptions(WritePermissions::all(), 86_400_000))->timeoutMs);

        $this->expectException(\App\Core\Query\Exception\InvalidQueryOptionsException::class);
        new ScriptOptions(WritePermissions::all(), 86_400_001);
    }
}

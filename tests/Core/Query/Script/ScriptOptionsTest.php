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
}

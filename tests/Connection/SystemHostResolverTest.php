<?php

declare(strict_types=1);

namespace App\Tests\Connection;

use App\Connection\SystemHostResolver;
use PHPUnit\Framework\TestCase;

final class SystemHostResolverTest extends TestCase
{
    public function testALiteralAddressIsItsOwnAnswer(): void
    {
        self::assertSame(['93.184.216.34'], (new SystemHostResolver())->resolve('93.184.216.34'));
        self::assertSame(['::1'], (new SystemHostResolver())->resolve('[::1]'));
    }

    public function testLocalhostResolvesToTheLoopback(): void
    {
        self::assertContains('127.0.0.1', (new SystemHostResolver())->resolve('localhost'));
    }

    public function testAnUnresolvableNameGivesNoAddress(): void
    {
        self::assertSame([], (new SystemHostResolver())->resolve('nowhere.invalid'));
    }

    public function testAnAlternativeWritingOfTheLoopbackIsStillTheLoopback(): void
    {
        self::assertContains('127.0.0.1', (new SystemHostResolver())->resolve('2130706433'));
    }
}

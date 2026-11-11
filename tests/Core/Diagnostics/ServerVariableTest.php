<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics;

use App\Core\Diagnostics\RestartNeed;
use App\Core\Diagnostics\ServerVariable;
use PHPUnit\Framework\TestCase;

final class ServerVariableTest extends TestCase
{
    public function testKeepsWhatTheEngineSays(): void
    {
        $variable = new ServerVariable('max_connections', '200', '151', true, 'CONFIG', RestartNeed::Pending, 'global');

        self::assertSame('200', $variable->value);
        self::assertSame('151', $variable->default);
        self::assertTrue($variable->changed);
        self::assertFalse($variable->masked);
        self::assertSame(RestartNeed::Pending, $variable->restart);
    }

    public function testASecretNeverCarriesItsValueNorItsDefault(): void
    {
        $variable = new ServerVariable('ssl_passphrase_command', 'vault read --token abc', 'x', true, 'CONFIG', null, null);

        self::assertTrue($variable->masked);
        self::assertNull($variable->value);
        self::assertNull($variable->default);
        self::assertTrue($variable->changed, 'Knowing that a secret differs from its default reveals nothing of it.');
    }

    public function testALongValueIsShortened(): void
    {
        $variable = new ServerVariable('sql_mode', str_repeat('A,', 600), null, false, null, null, null);

        self::assertNotNull($variable->value);
        self::assertSame(ServerVariable::MAX_VALUE_LENGTH, mb_strlen($variable->value));
        self::assertStringEndsWith('…', $variable->value);
    }

    public function testInvalidUtf8IsScrubbed(): void
    {
        $variable = new ServerVariable('x', "a\xffb", null, false, null, null, null);

        self::assertTrue(mb_check_encoding((string) $variable->value, 'UTF-8'));
    }
}

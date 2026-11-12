<?php

declare(strict_types=1);

namespace App\Tests\Core\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\LockConflict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LockConflictTest extends TestCase
{
    /**
     * @return iterable<string, array{Engine, ?string, ?int, ?LockConflict}>
     */
    public static function errors(): iterable
    {
        yield 'MySQL deadlock' => [Engine::MySql, '40001', 1213, LockConflict::Deadlock];
        yield 'MariaDB deadlock' => [Engine::MariaDb, '40001', 1213, LockConflict::Deadlock];
        yield 'MySQL lock wait timeout' => [Engine::MySql, 'HY000', 1205, LockConflict::LockTimeout];
        yield 'MariaDB lock wait timeout' => [Engine::MariaDb, 'HY000', 1205, LockConflict::LockTimeout];
        yield 'PostgreSQL deadlock' => [Engine::PostgreSql, '40P01', 7, LockConflict::Deadlock];
        yield 'PostgreSQL serialization failure is its own code' => [Engine::PostgreSql, '40001', 7, LockConflict::SerializationFailure];
        yield 'PostgreSQL lock not available' => [Engine::PostgreSql, '55P03', 7, LockConflict::LockTimeout];
        yield 'PostgreSQL query canceled stays a plain timeout' => [Engine::PostgreSql, '57014', 7, null];
        yield 'MySQL execution time stays a plain timeout' => [Engine::MySql, 'HY000', 3024, null];
        yield 'MySQL duplicate key' => [Engine::MySql, '23000', 1062, null];
        yield 'PostgreSQL native code 1205 is not a MySQL code' => [Engine::PostgreSql, 'HY000', 1205, null];
        yield 'MySQL SQLSTATE alone is not enough for a timeout' => [Engine::MySql, '55P03', null, null];
        yield 'unknown' => [Engine::MySql, null, null, null];
    }

    #[DataProvider('errors')]
    public function testClassifiesByEngineCodeNeverByText(Engine $engine, ?string $sqlState, ?int $code, ?LockConflict $expected): void
    {
        self::assertSame($expected, LockConflict::fromEngineError($engine, $sqlState, $code));
    }

    public function testStableCodes(): void
    {
        self::assertSame('deadlock', LockConflict::Deadlock->code());
        self::assertSame('serialization_failure', LockConflict::SerializationFailure->code());
        self::assertSame('lock_timeout', LockConflict::LockTimeout->code());
    }
}

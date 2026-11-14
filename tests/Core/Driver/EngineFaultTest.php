<?php

declare(strict_types=1);

namespace App\Tests\Core\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\EngineFault;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EngineFaultTest extends TestCase
{
    /**
     * @return iterable<string, array{Engine, ?string, ?int, ?EngineFault}>
     */
    public static function statementErrors(): iterable
    {
        foreach ([Engine::MySql, Engine::MariaDb] as $engine) {
            $name = $engine->value;
            yield "$name too many connections" => [$engine, 'HY000', 1040, EngineFault::TooManyConnections];
            yield "$name user connection limit" => [$engine, '42000', 1203, EngineFault::TooManyConnections];
            yield "$name account resource limit" => [$engine, 'HY000', 1226, EngineFault::TooManyConnections];
            yield "$name database access denied" => [$engine, '42000', 1044, EngineFault::PermissionDenied];
            yield "$name table access denied" => [$engine, '42000', 1142, EngineFault::PermissionDenied];
            yield "$name column access denied" => [$engine, '42000', 1143, EngineFault::PermissionDenied];
            yield "$name missing privilege" => [$engine, '42000', 1227, EngineFault::PermissionDenied];
            yield "$name routine access denied" => [$engine, '42000', 1370, EngineFault::PermissionDenied];
            yield "$name duplicate entry" => [$engine, '23000', 1062, EngineFault::DuplicateKey];
            yield "$name authentication failed" => [$engine, 'HY000', 1045, EngineFault::AuthenticationFailed];
            yield "$name insecure transport" => [$engine, 'HY000', 3159, EngineFault::AuthRequiresTls];
            yield "$name deadlock is not a fault of this family" => [$engine, '40001', 1213, null];
            yield "$name syntax error" => [$engine, '42000', 1064, null];
            yield "$name SQLSTATE alone is not enough" => [$engine, '23505', null, null];
        }
        yield 'PostgreSQL too many connections' => [Engine::PostgreSql, '53300', 7, EngineFault::TooManyConnections];
        yield 'PostgreSQL insufficient privilege' => [Engine::PostgreSql, '42501', 7, EngineFault::PermissionDenied];
        yield 'PostgreSQL unique violation' => [Engine::PostgreSql, '23505', 7, EngineFault::DuplicateKey];
        yield 'PostgreSQL invalid password' => [Engine::PostgreSql, '28P01', 7, EngineFault::AuthenticationFailed];
        yield 'PostgreSQL foreign key violation is not a duplicate' => [Engine::PostgreSql, '23503', 7, null];
        yield 'PostgreSQL native code 1062 is not a MySQL code' => [Engine::PostgreSql, 'HY000', 1062, null];
        yield 'PostgreSQL serialization failure belongs to locks' => [Engine::PostgreSql, '40001', 7, null];
        yield 'ClickHouse is not read here' => [Engine::ClickHouse, '23505', 1062, null];
        yield 'Redis is not read here' => [Engine::Redis, null, null, null];
        yield 'unknown' => [Engine::MySql, null, null, null];
    }

    #[DataProvider('statementErrors')]
    public function testClassifiesByEngineCodeNeverByText(Engine $engine, ?string $sqlState, ?int $code, ?EngineFault $expected): void
    {
        self::assertSame($expected, EngineFault::fromEngineError($engine, $sqlState, $code));
    }

    /**
     * Messages réels mesurés sur les serveurs (libpq rend 08006 pour tout refus à la connexion : seul le texte distingue).
     *
     * @return iterable<string, array{Engine, ?string, ?int, string, ?EngineFault}>
     */
    public static function connectionErrors(): iterable
    {
        $tls = 'connection to server at "h", port 5432 failed: FATAL:  no pg_hba.conf entry for host "x", user "u", database "d", no encryption';
        yield 'PostgreSQL requires TLS' => [Engine::PostgreSql, '08006', 7, $tls, EngineFault::AuthRequiresTls];
        yield 'PostgreSQL requires TLS, other wording' => [Engine::PostgreSql, '08006', 7, 'connection to server at "h", port 5432 failed: FATAL:  SSL/TLS required', EngineFault::AuthRequiresTls];
        yield 'PostgreSQL bad password' => [Engine::PostgreSql, '08006', 7, 'connection to server at "h", port 5432 failed: FATAL:  password authentication failed for user "u"', EngineFault::AuthenticationFailed];
        yield 'PostgreSQL reserved slots' => [Engine::PostgreSql, '08006', 7, 'connection to server at "h", port 5432 failed: FATAL:  remaining connection slots are reserved for roles with the SUPERUSER attribute', EngineFault::TooManyConnections];
        yield 'PostgreSQL too many clients' => [Engine::PostgreSql, '08006', 7, 'connection to server at "h", port 5432 failed: FATAL:  sorry, too many clients already', EngineFault::TooManyConnections];
        yield 'PostgreSQL role limit' => [Engine::PostgreSql, '08006', 7, 'connection to server at "h", port 5432 failed: FATAL:  too many connections for role "u"', EngineFault::TooManyConnections];
        yield 'PostgreSQL no CONNECT right' => [Engine::PostgreSql, '08006', 7, 'connection to server at "h", port 5432 failed: FATAL:  permission denied for database "d"', EngineFault::PermissionDenied];
        yield 'PostgreSQL pg_hba without TLS wording is not TLS' => [Engine::PostgreSql, '08006', 7, 'connection to server at "h", port 5432 failed: FATAL:  no pg_hba.conf entry for host "x", user "u", database "d", SSL encryption', null];
        yield 'PostgreSQL refused connection is not a fault of this family' => [Engine::PostgreSql, '08006', 7, 'connection to server at "h", port 1 failed: Connection refused', null];
        yield 'PostgreSQL real SQLSTATE wins' => [Engine::PostgreSql, '28P01', 7, 'whatever', EngineFault::AuthenticationFailed];
        yield 'MySQL bad password ignores text' => [Engine::MySql, 'HY000', 1045, "Access denied for user 'jean'@'10.0.0.1'", EngineFault::AuthenticationFailed];
        yield 'MariaDB insecure transport' => [Engine::MariaDb, 'HY000', 3159, 'Connections using insecure transport are prohibited', EngineFault::AuthRequiresTls];
        yield 'MySQL text alone is not read' => [Engine::MySql, 'HY000', null, 'Too many connections', null];
        yield 'MySQL unknown database is not a fault of this family' => [Engine::MySql, 'HY000', 1049, "Unknown database 'x'", null];
    }

    #[DataProvider('connectionErrors')]
    public function testConnectionErrors(Engine $engine, ?string $sqlState, ?int $code, string $message, ?EngineFault $expected): void
    {
        self::assertSame($expected, EngineFault::fromConnectionError($engine, $sqlState, $code, $message));
    }

    public function testStableCodesAndAdvice(): void
    {
        self::assertSame('too_many_connections', EngineFault::TooManyConnections->code());
        self::assertSame('engine_permission_denied', EngineFault::PermissionDenied->code());
        self::assertSame('duplicate_key', EngineFault::DuplicateKey->code());
        self::assertSame('auth_requires_tls', EngineFault::AuthRequiresTls->code());
        self::assertSame('auth_failed', EngineFault::AuthenticationFailed->code());

        self::assertTrue(EngineFault::TooManyConnections->retryable());
        self::assertSame(5, EngineFault::TooManyConnections->retryAfterSeconds());
        foreach ([EngineFault::PermissionDenied, EngineFault::DuplicateKey, EngineFault::AuthRequiresTls, EngineFault::AuthenticationFailed] as $fault) {
            self::assertFalse($fault->retryable());
            self::assertNull($fault->retryAfterSeconds());
        }
    }
}

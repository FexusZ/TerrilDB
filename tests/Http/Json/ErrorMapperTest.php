<?php

declare(strict_types=1);

namespace App\Tests\Http\Json;

use App\ApiToken\Exception\ApiTokenNotFoundException;
use App\ApiToken\Exception\InvalidApiTokenException;
use App\Connection\Exception\ConnectionNameAlreadyUsedException;
use App\Connection\Exception\ConnectionNotFoundException;
use App\Core\Connection\Engine;
use App\Core\Connection\Exception\InvalidConnectionParametersException;
use App\Core\Data\DataStatement;
use App\Core\Data\Exception\DataChangeFailedException;
use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\TableHolders;
use App\Core\Driver\EngineFault;
use App\Core\Driver\Exception\EngineFaultException;
use App\Core\Driver\Exception\LockConflictException;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\Exception\UnsupportedEngineException;
use App\Core\Driver\Exception\UnsupportedOperationException;
use App\Core\Driver\LockConflict;
use App\Core\Guard\GuardReason;
use App\Core\KeyValue\Exception\InvalidKeyException;
use App\Core\KeyValue\Exception\KeyNotFoundException;
use App\Core\Optimizer\Exception\PlanNotAvailableException;
use App\Core\Optimizer\Exception\UnreadablePlanException;
use App\Core\Query\Exception\InvalidQueryOptionsException;
use App\Core\Query\Exception\QueryFailedException;
use App\Core\Query\Exception\QueryNeedsConfirmationException;
use App\Core\Query\Exception\QueryRefusedException;
use App\Core\Query\Exception\QueryTimeoutException;
use App\Core\Schema\Change\DdlStatement;
use App\Core\Schema\Change\Exception\SchemaChangeFailedException;
use App\Core\Schema\Change\Exception\UnsupportedSchemaChangeException;
use App\Core\Schema\Exception\TableNotFoundException;
use App\Core\Schema\LockReason;
use App\Crypto\Exception\DecryptionFailedException;
use App\Entity\Exception\InvalidApiTokenDefinitionException;
use App\Entity\Exception\InvalidDatabaseConnectionException;
use App\Entity\Exception\InvalidUserException;
use App\Http\Json\BadRequestException;
use App\Http\Json\ErrorMapper;
use App\Http\Json\LockedException;
use App\Http\Json\TooManyAttemptsException;
use App\Security\Exception\AccountAlreadyExistsException;
use App\Security\Exception\InvalidCredentialsException;
use App\Security\Exception\InvalidSecondFactorException;
use App\Security\Exception\PasswordPolicyException;
use App\Security\Exception\SecondFactorRequiredException;
use App\Security\Exception\TotpAlreadyEnabledException;
use App\Security\Exception\TotpNotEnabledException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ErrorMapperTest extends TestCase
{
    /**
     * @return iterable<string, array{\Throwable, int, string}>
     */
    public static function mapped(): iterable
    {
        yield 'bad credentials' => [InvalidCredentialsException::create(), 401, 'invalid_credentials'];
        yield 'second factor required' => [SecondFactorRequiredException::create(), 401, 'second_factor_required'];
        yield 'bad second factor' => [InvalidSecondFactorException::create(), 401, 'invalid_second_factor'];
        yield 'weak password' => [new PasswordPolicyException('too short'), 422, 'weak_password'];
        yield 'account exists' => [AccountAlreadyExistsException::create(), 409, 'account_exists'];
        yield 'totp already enabled' => [TotpAlreadyEnabledException::create(), 409, 'totp_already_enabled'];
        yield 'totp not enabled' => [TotpNotEnabledException::create(), 409, 'totp_not_enabled'];
        yield 'invalid token' => [InvalidApiTokenException::create(), 401, 'invalid_token'];
        yield 'token not found' => [ApiTokenNotFoundException::create(), 404, 'not_found'];
        yield 'invalid token definition' => [new InvalidApiTokenDefinitionException('x'), 422, 'invalid_input'];
        yield 'invalid user' => [new InvalidUserException('x'), 422, 'invalid_input'];
        yield 'invalid connection' => [new InvalidDatabaseConnectionException('x'), 422, 'invalid_input'];
        yield 'invalid parameters' => [new InvalidConnectionParametersException('x'), 422, 'invalid_input'];
        yield 'invalid options' => [new InvalidQueryOptionsException('x'), 422, 'invalid_input'];
        yield 'bad request' => [new BadRequestException('x'), 400, 'bad_request'];
        yield 'connection not found' => [ConnectionNotFoundException::create(), 404, 'not_found'];
        yield 'table not found' => [TableNotFoundException::named('t'), 404, 'not_found'];
        yield 'name used' => [ConnectionNameAlreadyUsedException::create(), 409, 'connection_name_used'];
        yield 'target unreachable' => [TargetConnectionFailedException::create(), 502, 'target_unreachable'];
        yield 'unsupported engine' => [UnsupportedEngineException::for(Engine::MySql), 501, 'unsupported_engine'];
        yield 'locked' => [new LockedException(), 401, 'locked'];
        yield 'undecryptable' => [new DecryptionFailedException('x'), 401, 'locked'];
        yield 'invalid key' => [new InvalidKeyException('x'), 422, 'invalid_input'];
        yield 'key not found' => [KeyNotFoundException::named('k'), 404, 'not_found'];
        yield 'unsupported operation' => [UnsupportedOperationException::for(Engine::Redis, 'tables'), 501, 'unsupported_operation'];
        yield 'diagnostics failed' => [\App\Core\Diagnostics\Exception\DiagnosticsFailedException::create(new \RuntimeException('engine detail')), 502, 'diagnostics_failed'];
        yield 'plan not available' => [PlanNotAvailableException::for(Engine::ClickHouse), 501, 'plan_unavailable'];
        yield 'unreadable plan' => [UnreadablePlanException::create(), 502, 'unreadable_plan'];
        yield 'query timeout' => [QueryTimeoutException::afterMilliseconds(100), 408, 'query_timeout'];
    }

    #[DataProvider('mapped')]
    public function testMapsDomainErrorsToStableCodes(\Throwable $error, int $status, string $code): void
    {
        $response = (new ErrorMapper())->map($error);

        self::assertNotNull($response);
        self::assertSame($status, $response->status);
        self::assertSame($code, $response->code);
    }

    public function testAnUnsupportedSchemaChangeNamesTheObjectAndTheReason(): void
    {
        $response = (new ErrorMapper())->map(new UnsupportedSchemaChangeException(LockReason::Generated, 'total'));

        self::assertNotNull($response);
        self::assertSame(422, $response->status);
        self::assertSame('unsupported_schema_change', $response->code);
        self::assertSame(['reason' => 'generated', 'subject' => 'total'], $response->extra);
    }

    public function testRefusedQueriesCarryTheirReason(): void
    {
        $response = (new ErrorMapper())->map(new QueryRefusedException(GuardReason::NotReadOnly));

        self::assertNotNull($response);
        self::assertSame(403, $response->status);
        self::assertSame('query_refused', $response->code);
        self::assertSame(['reason' => 'not_read_only'], $response->extra);
    }

    public function testConfirmationRequestsCarryTheirReason(): void
    {
        $response = (new ErrorMapper())->map(new QueryNeedsConfirmationException(GuardReason::DestructiveWithoutWhere));

        self::assertNotNull($response);
        self::assertSame(409, $response->status);
        self::assertSame('confirmation_required', $response->code);
        self::assertSame(['reason' => 'destructive_without_where'], $response->extra);
    }

    public function testEngineErrorsShowTheEngineMessage(): void
    {
        $response = (new ErrorMapper())->map(new QueryFailedException('syntax error at or near "selec"'));

        self::assertNotNull($response);
        self::assertSame(400, $response->status);
        self::assertSame('query_failed', $response->code);
        self::assertSame(['message' => 'syntax error at or near "selec"'], $response->extra);
    }

    public function testBusyHealthMeasurementsAreRetryable(): void
    {
        $response = (new ErrorMapper())->map(new \App\Core\Diagnostics\Health\HealthBusyException(30));

        self::assertNotNull($response);
        self::assertSame(429, $response->status);
        self::assertSame('too_many_attempts', $response->code);
        self::assertSame(['Retry-After' => '30'], $response->headers);
    }

    public function testTooManyAttemptsTellWhenToRetry(): void
    {
        $response = (new ErrorMapper())->map(new TooManyAttemptsException(42));

        self::assertNotNull($response);
        self::assertSame(429, $response->status);
        self::assertSame('too_many_attempts', $response->code);
        self::assertSame(['Retry-After' => '42'], $response->headers);
        self::assertSame(['retryAfter' => 42], $response->extra);
    }

    public function testPasswordPolicyMessageIsKept(): void
    {
        $response = (new ErrorMapper())->map(new PasswordPolicyException('The password must have at least 12 characters.'));

        self::assertNotNull($response);
        self::assertSame(['message' => 'The password must have at least 12 characters.'], $response->extra);
    }

    public function testUnknownErrorsAreNotMapped(): void
    {
        self::assertNull((new ErrorMapper())->map(new \RuntimeException('boom')));
    }

    public function testNeverLeaksTheMessageOfAnUnreachableTarget(): void
    {
        $response = (new ErrorMapper())->map(TargetConnectionFailedException::create(new \PDOException('SQLSTATE[HY000] [2002] host db.internal')));

        self::assertNotNull($response);
        self::assertSame([], $response->extra);
    }

    public function testLockConflictsOfAStatementAreRetryableAndNeverShowTheEngineMessage(): void
    {
        $response = (new ErrorMapper())->map(new LockConflictException(LockConflict::Deadlock, new \PDOException("Deadlock found; value 'jean@example.org'")));

        self::assertNotNull($response);
        self::assertSame(409, $response->status);
        self::assertSame('deadlock', $response->code);
        self::assertSame(['retryable' => true], $response->extra);
        self::assertSame('lock_timeout', (new ErrorMapper())->map(new LockConflictException(LockConflict::LockTimeout))?->code);
    }

    public function testASerializationFailureIsReportedUnderItsOwnRetryableCode(): void
    {
        $response = (new ErrorMapper())->map(new LockConflictException(LockConflict::SerializationFailure, new \PDOException("could not serialize access; value 'jean@example.org'")));

        self::assertNotNull($response);
        self::assertSame(409, $response->status);
        self::assertSame('serialization_failure', $response->code);
        self::assertSame(['retryable' => true], $response->extra);
    }

    public function testDataChangeBlockedByALockKeepsItsStatementButNotTheMessage(): void
    {
        $statement = new DataStatement('UPDATE t SET a = ? WHERE id = ?', ['x', 1], 'UPDATE t SET a = \'x\'', 'update', 't', false, null, []);
        $error = new DataChangeFailedException("duplicate entry 'secret'", 0, $statement, null, LockConflict::LockTimeout);

        $response = (new ErrorMapper())->map($error);

        self::assertNotNull($response);
        self::assertSame(409, $response->status);
        self::assertSame('lock_timeout', $response->code);
        self::assertTrue($response->extra['retryable']);
        self::assertSame(0, $response->extra['applied']);
        self::assertArrayNotHasKey('message', $response->extra);
    }

    public function testOrdinaryDataChangeFailureIsNotRetryable(): void
    {
        $statement = new DataStatement('UPDATE t SET a = ?', ['x'], 'UPDATE t SET a = \'x\'', 'update', 't', false, null, []);

        $response = (new ErrorMapper())->map(new DataChangeFailedException('boom', 0, $statement));

        self::assertSame('data_change_failed', $response?->code);
        self::assertFalse($response->extra['retryable']);
    }

    public function testSchemaChangeBlockedByALockIsRetryable(): void
    {
        $statement = new DdlStatement('ALTER TABLE t ADD c int', 'add_column', 't', false);
        $response = (new ErrorMapper())->map(new SchemaChangeFailedException('Lock wait timeout', [], $statement, null, LockConflict::LockTimeout));

        self::assertSame(409, $response?->status);
        self::assertSame('lock_timeout', $response->code);
        self::assertTrue($response->extra['retryable']);
        self::assertArrayNotHasKey('message', $response->extra);
        self::assertSame('schema_change_failed', (new ErrorMapper())->map(new SchemaChangeFailedException('x', [], $statement))?->code);
    }

    public function testSchemaChangeBlockedByALockAfterAppliedStatementsIsNotRetryable(): void
    {
        $done = new DdlStatement('ALTER TABLE t ADD c int', 'add_column', 't', false);
        $failed = new DdlStatement('ALTER TABLE t ADD d int', 'add_column', 't', false);

        $response = (new ErrorMapper())->map(new SchemaChangeFailedException('Lock wait timeout', [$done], $failed, null, LockConflict::LockTimeout));

        self::assertSame('lock_timeout', $response?->code);
        self::assertFalse($response->extra['retryable']);
        self::assertIsArray($response->extra['applied']);
        self::assertCount(1, $response->extra['applied']);
    }

    public function testLockTimeoutOfAStructureChangeKeepsTheHolderReport(): void
    {
        $statement = new DdlStatement('ALTER TABLE t ADD c int', 'add_column', 't', false);
        $error = (new SchemaChangeFailedException('Lock wait timeout', [], $statement, null, LockConflict::LockTimeout))
            ->withHolders(TableHolders::unreadable(DiagnosticStatus::unsupported('lock_check_failed')));

        $response = (new ErrorMapper())->map($error);

        self::assertSame('lock_timeout', $response?->code);
        self::assertTrue($response->extra['lockTimeout']);
        self::assertTrue($response->extra['retryable']);
        self::assertIsArray($response->extra['lockCheck']);
        self::assertArrayNotHasKey('message', $response->extra);
        $deadlock = (new ErrorMapper())->map(new SchemaChangeFailedException('x', [], $statement, null, LockConflict::Deadlock));
        self::assertFalse($deadlock?->extra['lockTimeout']);
        self::assertNull($deadlock->extra['lockCheck']);
    }

    /**
     * @return iterable<string, array{EngineFault, int, string, bool}>
     */
    public static function faults(): iterable
    {
        yield 'too many connections' => [EngineFault::TooManyConnections, 503, 'too_many_connections', true];
        yield 'permission denied by the engine' => [EngineFault::PermissionDenied, 403, 'engine_permission_denied', false];
        yield 'duplicate key' => [EngineFault::DuplicateKey, 409, 'duplicate_key', false];
        yield 'TLS required' => [EngineFault::AuthRequiresTls, 502, 'auth_requires_tls', false];
        yield 'authentication failed' => [EngineFault::AuthenticationFailed, 502, 'auth_failed', false];
    }

    #[DataProvider('faults')]
    public function testEngineFaultsHaveAStableCodeAnAdviceAndNeverTheEngineMessage(EngineFault $fault, int $status, string $code, bool $retryable): void
    {
        $response = (new ErrorMapper())->map(new EngineFaultException($fault, new \PDOException("Duplicate entry 'jean@example.org' for key 'email' at 10.0.0.7")));

        self::assertNotNull($response);
        self::assertSame($status, $response->status);
        self::assertSame($code, $response->code);
        self::assertSame($retryable, $response->extra['retryable']);
        self::assertArrayNotHasKey('message', $response->extra);
        self::assertStringNotContainsString('jean', json_encode($response, \JSON_THROW_ON_ERROR));
        if ($retryable) {
            self::assertSame(5, $response->extra['retryAfter']);
            self::assertSame(['Retry-After' => '5'], $response->headers);
        } else {
            self::assertArrayNotHasKey('retryAfter', $response->extra);
            self::assertSame([], $response->headers);
        }
    }

    public function testADataChangeRefusedByTheEngineKeepsItsStatementButNotTheMessage(): void
    {
        $statement = new DataStatement('INSERT INTO t (a) VALUES (?)', ['x'], 'INSERT INTO t (a) VALUES (\'x\')', 'insert', 't', false, null, []);
        $error = new DataChangeFailedException("Duplicate entry 'x'", 0, $statement, null, null, EngineFault::DuplicateKey);

        $response = (new ErrorMapper())->map($error);

        self::assertNotNull($response);
        self::assertSame(409, $response->status);
        self::assertSame('duplicate_key', $response->code);
        self::assertFalse($response->extra['retryable']);
        self::assertSame(0, $response->extra['applied']);
        self::assertArrayNotHasKey('message', $response->extra);
        self::assertSame('too_many_connections', (new ErrorMapper())->map(new DataChangeFailedException('x', 0, $statement, null, null, EngineFault::TooManyConnections))?->code);
    }

    public function testASchemaChangeRefusedByTheEngineKeepsWhatWasApplied(): void
    {
        $done = new DdlStatement('ALTER TABLE t ADD c int', 'add_column', 't', false);
        $failed = new DdlStatement('ALTER TABLE t ADD d int', 'add_column', 't', false);

        $response = (new ErrorMapper())->map(new SchemaChangeFailedException("SELECT command denied to user 'jean'", [$done], $failed, null, null, null, EngineFault::PermissionDenied));

        self::assertSame(403, $response?->status);
        self::assertSame('engine_permission_denied', $response->code);
        self::assertFalse($response->extra['retryable']);
        self::assertIsArray($response->extra['applied']);
        self::assertCount(1, $response->extra['applied']);
        self::assertArrayNotHasKey('message', $response->extra);

        $full = (new ErrorMapper())->map(new SchemaChangeFailedException('x', [], $failed, null, null, null, EngineFault::TooManyConnections));
        self::assertTrue($full?->extra['retryable'], 'rien d\'appliqué : on peut réessayer');
        $partial = (new ErrorMapper())->map(new SchemaChangeFailedException('x', [$done], $failed, null, null, null, EngineFault::TooManyConnections));
        self::assertFalse($partial?->extra['retryable'], 'déjà appliqué en partie : on ne rejoue pas tel quel');
    }

    public function testAnUnreachableTargetWithAKnownRefusalKeepsItsOwnCode(): void
    {
        $response = (new ErrorMapper())->map(TargetConnectionFailedException::create(new \PDOException('SQLSTATE[HY000] [1045] Access denied for user jean'), EngineFault::AuthenticationFailed));

        self::assertSame('auth_failed', $response?->code);
        self::assertSame(502, $response->status);
        self::assertStringNotContainsString('jean', json_encode($response, \JSON_THROW_ON_ERROR));
        self::assertSame('target_unreachable', (new ErrorMapper())->map(TargetConnectionFailedException::create())?->code);
    }
}

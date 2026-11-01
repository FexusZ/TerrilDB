<?php

declare(strict_types=1);

namespace App\Tests\Http\Json;

use App\ApiToken\Exception\ApiTokenNotFoundException;
use App\ApiToken\Exception\InvalidApiTokenException;
use App\Connection\Exception\ConnectionNameAlreadyUsedException;
use App\Connection\Exception\ConnectionNotFoundException;
use App\Core\Connection\Engine;
use App\Core\Connection\Exception\InvalidConnectionParametersException;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\Exception\UnsupportedEngineException;
use App\Core\Guard\GuardReason;
use App\Core\Query\Exception\InvalidQueryOptionsException;
use App\Core\Query\Exception\QueryFailedException;
use App\Core\Query\Exception\QueryNeedsConfirmationException;
use App\Core\Query\Exception\QueryRefusedException;
use App\Core\Query\Exception\QueryTimeoutException;
use App\Core\Schema\Exception\TableNotFoundException;
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
}

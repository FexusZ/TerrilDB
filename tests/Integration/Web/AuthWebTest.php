<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class AuthWebTest extends WebApiTestCase
{
    public function testTheProfileRequiresAuthentication(): void
    {
        $body = $this->call('GET', '/web/me');

        self::assertSame(401, $this->statusCode());
        self::assertSame(['error' => 'unauthenticated'], $body);
    }

    public function testStateChangingRequestsNeedTheCsrfHeader(): void
    {
        $body = $this->call('POST', '/web/login', ['login' => 'alice', 'password' => 'x'], csrf: false);

        self::assertSame(403, $this->statusCode());
        self::assertSame('csrf', $body['error']);
    }

    public function testTheHealthCheckStaysPublic(): void
    {
        $this->call('GET', '/api/health');

        self::assertSame(200, $this->statusCode());
    }

    public function testRegistrationIsOpenForTheFirstAccountOnly(): void
    {
        $first = $this->call('POST', '/web/register', ['email' => 'alice@example.test', 'username' => 'alice', 'password' => self::PASSWORD]);
        self::assertSame(201, $this->statusCode());
        self::assertIsArray($first['user']);
        self::assertSame('alice', $first['user']['username']);
        self::assertArrayNotHasKey('passwordHash', $first['user']);

        $second = $this->call('POST', '/web/register', ['email' => 'bob@example.test', 'username' => 'bob', 'password' => self::PASSWORD]);
        self::assertSame(403, $this->statusCode());
        self::assertSame('registration_closed', $second['error']);
    }

    public function testRegistrationRefusesWeakPasswordsAndInvalidIdentifiers(): void
    {
        $weak = $this->call('POST', '/web/register', ['email' => 'alice@example.test', 'username' => 'alice', 'password' => 'short']);
        self::assertSame(422, $this->statusCode());
        self::assertSame('weak_password', $weak['error']);

        $invalid = $this->call('POST', '/web/register', ['email' => 'not-an-email', 'username' => 'alice', 'password' => self::PASSWORD]);
        self::assertSame(422, $this->statusCode());
        self::assertSame('invalid_input', $invalid['error']);

        $malformed = $this->call('POST', '/web/register', ['email' => 'alice@example.test', 'username' => 'alice', 'password' => 12345]);
        self::assertSame(400, $this->statusCode());
        self::assertSame('bad_request', $malformed['error']);
        self::assertStringNotContainsString('12345', json_encode($malformed, \JSON_THROW_ON_ERROR));
    }

    public function testUnknownAccountAndWrongPasswordLookTheSame(): void
    {
        $this->seedUser();

        $unknown = $this->call('POST', '/web/login', ['login' => 'nobody', 'password' => self::PASSWORD]);
        $unknownStatus = $this->statusCode();
        $wrong = $this->call('POST', '/web/login', ['login' => 'alice', 'password' => 'not the password!!']);

        self::assertSame($unknownStatus, $this->statusCode());
        self::assertSame(401, $this->statusCode());
        self::assertSame($unknown, $wrong);
        self::assertSame('invalid_credentials', $wrong['error']);
    }

    public function testLoginOpensASessionWithUsernameOrEmail(): void
    {
        $this->seedUser();

        $body = $this->call('POST', '/web/login', ['login' => 'ALICE@example.test', 'password' => self::PASSWORD]);

        self::assertSame(200, $this->statusCode());
        self::assertIsArray($body['user']);
        self::assertSame('alice', $body['user']['username']);
        self::assertFalse($body['user']['totpEnabled']);
        self::assertStringNotContainsString('argon2id', json_encode($body, \JSON_THROW_ON_ERROR));
        $names = array_map(static fn ($cookie): string => $cookie->getName(), $this->client->getCookieJar()->all());
        self::assertContains('TERRILDB_SESSION', $names);
        self::assertContains('TERRILDB_UNLOCK', $names);

        $me = $this->call('GET', '/web/me');
        self::assertSame(200, $this->statusCode());
        self::assertIsArray($me['user']);
        self::assertSame('alice', $me['user']['username']);
    }

    public function testLoginByUsernameWorksToo(): void
    {
        $this->seedAndLogin();

        $this->call('GET', '/web/me');

        self::assertSame(200, $this->statusCode());
    }

    public function testLogoutEndsTheSessionAndClearsTheUnlockCookie(): void
    {
        $this->seedAndLogin();

        $this->call('POST', '/web/logout');
        self::assertSame(204, $this->statusCode());
        $cleared = array_filter(
            $this->client->getResponse()->headers->getCookies(),
            static fn ($cookie): bool => 'TERRILDB_UNLOCK' === $cookie->getName() && $cookie->isCleared(),
        );
        self::assertNotEmpty($cleared);

        $this->call('GET', '/web/me');
        self::assertSame(401, $this->statusCode());
    }

    public function testRepeatedFailuresAreThrottled(): void
    {
        $this->seedUser();

        for ($i = 0; $i < 8; ++$i) {
            $this->call('POST', '/web/login', ['login' => 'alice', 'password' => 'not the password!!']);
            self::assertSame(401, $this->statusCode());
        }
        $body = $this->call('POST', '/web/login', ['login' => 'alice', 'password' => 'not the password!!']);

        self::assertSame(429, $this->statusCode());
        self::assertSame('too_many_attempts', $body['error']);
        self::assertTrue($this->client->getResponse()->headers->has('Retry-After'));

        // Même le bon mot de passe attend : sinon le plafond ne protégerait rien.
        $this->call('POST', '/web/login', ['login' => 'alice', 'password' => self::PASSWORD]);
        self::assertSame(429, $this->statusCode());
    }

    public function testASuccessfulLoginResetsTheAccountCounter(): void
    {
        $this->seedUser();

        for ($round = 0; $round < 3; ++$round) {
            for ($i = 0; $i < 5; ++$i) {
                $this->call('POST', '/web/login', ['login' => 'alice', 'password' => 'not the password!!']);
                self::assertSame(401, $this->statusCode());
            }
            $this->login();
        }
    }

    public function testUnknownRoutesAnswerInJson(): void
    {
        $this->seedAndLogin();

        $body = $this->call('GET', '/web/nothing-here');

        self::assertSame(404, $this->statusCode());
        self::assertSame('not_found', $body['error']);
    }
}

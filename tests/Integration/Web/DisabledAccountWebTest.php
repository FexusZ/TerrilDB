<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Repository\UserRepository;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

#[Group('integration')]
final class DisabledAccountWebTest extends WebApiTestCase
{
    private function setDisabled(Uuid $id, bool $disabled): void
    {
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $user = $users->find($id);
        self::assertNotNull($user);
        $disabled ? $user->disable(new \DateTimeImmutable()) : $user->enable();
        $users->save($user);
    }

    public function testADisabledAccountCannotLogInAndTheAnswerIsThatOfAWrongPassword(): void
    {
        $user = $this->seedUser('alice');
        $this->call('POST', '/web/login', ['login' => 'alice', 'password' => 'not the password!!']);
        $wrong = [$this->statusCode(), (string) $this->client->getResponse()->getContent()];
        $this->setDisabled($user->getId(), true);

        $this->call('POST', '/web/login', ['login' => 'alice', 'password' => self::PASSWORD]);

        self::assertSame($wrong, [$this->statusCode(), (string) $this->client->getResponse()->getContent()]);
        $this->call('GET', '/web/me');
        self::assertSame(401, $this->statusCode());
    }

    public function testAnOpenSessionStopsWorkingWhenTheAccountIsDisabledAndComesBackNeverWithoutLogin(): void
    {
        $user = $this->seedAndLogin('alice');
        $this->call('GET', '/web/me');
        self::assertSame(200, $this->statusCode());

        $this->setDisabled($user->getId(), true);
        $this->call('GET', '/web/me');
        self::assertSame(401, $this->statusCode());
        $this->call('GET', '/web/connections');
        self::assertSame(401, $this->statusCode());
    }

    public function testTheApiTokensOfADisabledAccountAreRefusedButKeptAndWorkAgainOnceEnabled(): void
    {
        $user = $this->seedAndLogin('alice');
        $created = $this->call('POST', '/web/tokens', ['name' => 'Assistant', 'allConnections' => true, 'lifetimeDays' => 30]);
        self::assertSame(201, $this->statusCode());
        $token = $created['token'];
        self::assertIsString($token);
        $this->client->getCookieJar()->clear();
        $bearer = ['HTTP_AUTHORIZATION' => 'Bearer '.$token];

        $this->setDisabled($user->getId(), true);
        $this->call('GET', '/api/v1/token', csrf: false, extraServer: $bearer);
        self::assertSame(401, $this->statusCode());
        self::assertSame('Bearer', $this->client->getResponse()->headers->get('WWW-Authenticate'));

        $this->setDisabled($user->getId(), false);
        $this->call('GET', '/api/v1/token', csrf: false, extraServer: $bearer);
        self::assertSame(200, $this->statusCode());
    }

    public function testARecoveryPhraseDoesNotReopenADisabledAccount(): void
    {
        $user = $this->seedAndLogin('alice');
        $phrase = $this->call('POST', '/web/account/recovery-phrase', ['password' => self::PASSWORD])['phrase'];
        self::assertIsString($phrase);
        $this->call('POST', '/web/logout');
        $this->setDisabled($user->getId(), true);

        $this->call('POST', '/web/recover', ['login' => 'alice', 'phrase' => $phrase, 'newPassword' => 'a brand new passphrase']);

        self::assertSame(403, $this->statusCode());
        $this->setDisabled($user->getId(), false);
        $this->call('POST', '/web/login', ['login' => 'alice', 'password' => self::PASSWORD]);
        self::assertSame(200, $this->statusCode(), 'the password must be unchanged');
    }

    public function testASuccessfulLoginRecordsTheLastLogin(): void
    {
        $user = $this->seedUser('alice');
        self::assertNull($user->getLastLoginAt());

        $this->login('alice');

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        self::assertNotNull($users->find($user->getId())?->getLastLoginAt());
    }
}

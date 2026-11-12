<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Repository\UserRepository;
use App\Security\LoginService;
use Doctrine\ORM\EntityManagerInterface;
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

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertNotNull($entityManager->getConnection()->fetchOne('SELECT last_login_at FROM app_user WHERE id = ?', [$user->getId()->toRfc4122()]));
    }

    public function testALoginRacingAnotherWriteOnTheSameAccountStillSucceeds(): void
    {
        $user = $this->seedUser('alice');
        $login = self::getContainer()->get(LoginService::class);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(LoginService::class, $login);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        // Une autre connexion simultanée a déjà fait avancer la version de la ligne que celle-ci a lue.
        $entityManager->getConnection()->executeStatement('UPDATE app_user SET version = version + 1 WHERE id = ?', [$user->getId()->toRfc4122()]);

        $result = $login->authenticate('alice', self::PASSWORD);
        $result->key->wipe();

        $row = $entityManager->getConnection()->fetchAssociative('SELECT last_login_at, version FROM app_user WHERE id = ?', [$user->getId()->toRfc4122()]);
        self::assertIsArray($row);
        self::assertNotNull($row['last_login_at']);
        self::assertSame(2, $row['version']);
    }
}

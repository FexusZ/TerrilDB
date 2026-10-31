<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\Crypto\KeyDerivation;
use App\Crypto\SecretCipher;
use App\Repository\UserRepository;
use App\Security\AuthPasswordHasher;
use App\Security\Exception\InvalidCredentialsException;
use App\Security\Exception\SecondFactorRequiredException;
use App\Security\LoginService;
use App\Security\PasswordPolicy;
use App\Security\UserRegistrar;
use App\Tests\Integration\Persistence\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;

#[Group('integration')]
final class LoginServiceTest extends DatabaseTestCase
{
    private const PASSWORD = 'correct horse battery';

    private UserRepository $repository;
    private AuthPasswordHasher $hasher;
    private KeyDerivation $derivation;
    private LoginService $login;

    protected function setUp(): void
    {
        parent::setUp();

        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);
        $this->repository = $repository;

        // Coûts réduits : la suite vérifie le comportement, pas la dureté d'Argon2id.
        $this->hasher = new AuthPasswordHasher(opsLimit: 3, memLimit: 16384);
        $this->derivation = new KeyDerivation(opsLimit: 1, memLimit: 8192);
        $this->login = new LoginService($this->hasher, $this->derivation, $this->repository);

        (new UserRegistrar(
            new PasswordPolicy(),
            $this->hasher,
            $this->derivation,
            $this->repository,
            new MockClock('2026-10-29 12:00:00 UTC'),
        ))->register('alice@example.test', 'alice', self::PASSWORD);
        $this->entityManager->clear();
    }

    public function testLogsInByEmailOrUsernameWhateverTheCase(): void
    {
        foreach (['alice@example.test', 'ALICE@Example.test', 'alice', 'Alice'] as $login) {
            $result = $this->login->authenticate($login, self::PASSWORD);

            self::assertSame('alice', $result->user->getUsername(), $login);
        }
    }

    public function testReturnsTheKeyDerivedFromThePassword(): void
    {
        $result = $this->login->authenticate('alice', self::PASSWORD);

        $cipher = new SecretCipher();
        $blob = $cipher->encrypt('db-credentials', $result->key, 'connection-1');
        $independentKey = $this->derivation->derive(self::PASSWORD, $result->user->getKdfParameters());

        self::assertSame('db-credentials', $cipher->decrypt($blob, $independentKey, 'connection-1'));
    }

    public function testAWrongPasswordIsRefused(): void
    {
        $this->expectException(InvalidCredentialsException::class);

        $this->login->authenticate('alice', 'another password entirely');
    }

    public function testAnUnknownLoginIsRefusedWithTheSameError(): void
    {
        $messages = [];
        foreach ([['alice', 'wrong password!!'], ['nobody', self::PASSWORD], ['nobody@example.test', self::PASSWORD]] as [$login, $password]) {
            try {
                $this->login->authenticate($login, $password);
                self::fail('Expected a refusal.');
            } catch (InvalidCredentialsException $e) {
                $messages[] = $e->getMessage();
            }
        }

        self::assertCount(1, array_unique($messages), 'rien ne distingue un login inconnu d\'un mauvais mot de passe');
    }

    public function testEmptyOrOversizedPasswordsAreRefusedAsInvalidCredentials(): void
    {
        foreach (['', str_repeat('a', 5000)] as $password) {
            try {
                $this->login->authenticate('alice', $password);
                self::fail('Expected a refusal.');
            } catch (InvalidCredentialsException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testASecondFactorIsRequiredWhenTotpIsEnabled(): void
    {
        $user = $this->repository->findByLogin('alice');
        self::assertNotNull($user);
        $user->enableTotp('v1:encrypted-secret', []);
        $this->repository->save($user);
        $this->entityManager->clear();

        $this->expectException(SecondFactorRequiredException::class);

        $this->login->authenticate('alice', self::PASSWORD);
    }

    public function testTheHashIsUpgradedWhenTheConfiguredCostWasRaised(): void
    {
        $stronger = new AuthPasswordHasher(opsLimit: 4, memLimit: 32768);
        $login = new LoginService($stronger, $this->derivation, $this->repository);
        $before = $this->repository->findByLogin('alice')?->getPasswordHash();
        $this->entityManager->clear();

        $login->authenticate('alice', self::PASSWORD);
        $this->entityManager->clear();

        $after = $this->repository->findByLogin('alice')?->getPasswordHash();
        self::assertNotNull($after);
        self::assertNotSame($before, $after);
        self::assertFalse($stronger->needsRehash($after));
        self::assertTrue($stronger->verify($after, self::PASSWORD));
    }

    public function testAnUpToDateHashIsLeftAlone(): void
    {
        $before = $this->repository->findByLogin('alice')?->getPasswordHash();
        $this->entityManager->clear();

        $this->login->authenticate('alice', self::PASSWORD);
        $this->entityManager->clear();

        self::assertSame($before, $this->repository->findByLogin('alice')?->getPasswordHash());
    }
}

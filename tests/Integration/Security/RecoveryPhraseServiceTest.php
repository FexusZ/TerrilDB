<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\ApiToken\ApiTokenService;
use App\Connection\ConnectionManager;
use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Crypto\KeyDerivation;
use App\Crypto\RecoveryPhrase;
use App\Crypto\SecretCipher;
use App\Crypto\SecretRotator;
use App\Crypto\SessionKeyWrapper;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Repository\DatabaseConnectionRepository;
use App\Repository\UserRepository;
use App\Security\AuthPasswordHasher;
use App\Security\Exception\InvalidCredentialsException;
use App\Security\Exception\InvalidRecoveryException;
use App\Security\Exception\PasswordPolicyException;
use App\Security\LoginService;
use App\Security\PasswordChanger;
use App\Security\PasswordPolicy;
use App\Security\RecoveryCodeService;
use App\Security\RecoveryPhraseService;
use App\Security\TotpService;
use App\Security\UserRegistrar;
use App\Tests\Integration\Persistence\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[Group('integration')]
final class RecoveryPhraseServiceTest extends DatabaseTestCase
{
    private const OLD = 'correct horse battery';
    private const NEW = 'a brand new passphrase';

    private UserRepository $users;
    private KeyDerivation $derivation;
    private ConnectionManager $manager;
    private PasswordChanger $changer;
    private RecoveryPhraseService $service;
    private LoginService $login;
    private User $user;
    private Uuid $connectionId;

    protected function setUp(): void
    {
        parent::setUp();

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;
        $repository = self::getContainer()->get(DatabaseConnectionRepository::class);
        self::assertInstanceOf(DatabaseConnectionRepository::class, $repository);
        $tokens = self::getContainer()->get(ApiTokenRepository::class);
        self::assertInstanceOf(ApiTokenRepository::class, $tokens);

        $hasher = new AuthPasswordHasher(opsLimit: 3, memLimit: 16384);
        $this->derivation = new KeyDerivation(opsLimit: 1, memLimit: 8192);
        $clock = new MockClock('2026-11-01 12:00:00 UTC');
        $this->manager = new ConnectionManager(new SecretCipher(), $repository, $clock);
        $this->changer = new PasswordChanger(new PasswordPolicy(), $hasher, new SecretRotator($this->derivation, new SecretCipher()), $users, $repository, new ApiTokenService(new SessionKeyWrapper(), $tokens, $this->manager, $clock));
        $this->service = new RecoveryPhraseService(new SessionKeyWrapper(), $this->changer, $users, $clock);
        $this->login = new LoginService($hasher, $this->derivation, $users, new TotpService(), new SecretCipher(), new RecoveryCodeService(), $clock);

        $this->user = (new UserRegistrar(new PasswordPolicy(), $hasher, $this->derivation, $users, $clock))->register('alice@example.test', 'alice', self::OLD);
        $key = $this->derivation->derive(self::OLD, $this->user->getKdfParameters());
        $this->connectionId = $this->manager->create($this->user, $key, 'Production', Engine::PostgreSql, new ConnectionParameters('db.exemple.test', 5432, 'shop', 'lecteur', 'secret-value'))->getId();
    }

    private function enable(): RecoveryPhrase
    {
        return $this->service->enable($this->user, $this->derivation->derive(self::OLD, $this->user->getKdfParameters()));
    }

    public function testEnablingStoresOnlyAnEncryptedCopyOfTheKey(): void
    {
        $phrase = $this->enable();

        self::assertTrue($this->user->hasRecovery());
        $stored = (string) $this->user->getRecoveryKey();
        self::assertMatchesRegularExpression('/^v1:/', $stored);
        self::assertStringNotContainsString(str_replace('-', '', $phrase->toString()), $stored);
        $key = $this->derivation->derive(self::OLD, $this->user->getKdfParameters());
        self::assertStringNotContainsString(base64_encode($key->bytes()), $stored);
    }

    public function testThePhraseResetsTheForgottenPasswordAndKeepsTheConnections(): void
    {
        $phrase = $this->enable();

        $newKey = $this->service->reset($this->user, $phrase->toString(), self::NEW);
        $this->entityManager->clear();

        self::assertSame('alice', $this->login->authenticate('alice', self::NEW)->user->getUsername());
        $user = $this->users->findByLogin('alice');
        self::assertInstanceOf(User::class, $user);
        self::assertSame('secret-value', $this->manager->reveal($user, $newKey, $this->connectionId)->password());
    }

    public function testTheOldPasswordStopsWorking(): void
    {
        $phrase = $this->enable();
        $this->service->reset($this->user, $phrase->toString(), self::NEW);
        $this->entityManager->clear();

        $this->expectException(InvalidCredentialsException::class);

        $this->login->authenticate('alice', self::OLD);
    }

    public function testAPhraseServesOnce(): void
    {
        $phrase = $this->enable();
        $this->service->reset($this->user, $phrase->toString(), self::NEW);

        self::assertFalse($this->user->hasRecovery());
        $this->expectException(InvalidRecoveryException::class);
        $this->service->reset($this->user, $phrase->toString(), 'yet another passphrase');
    }

    public function testAWrongOrMalformedPhraseIsRefusedWithoutChangingAnything(): void
    {
        $this->enable();
        $hash = $this->user->getPasswordHash();

        foreach ([RecoveryPhrase::generate()->toString(), 'nonsense', ''] as $typed) {
            try {
                $this->service->reset($this->user, $typed, self::NEW);
                self::fail('The phrase must be refused.');
            } catch (InvalidRecoveryException $e) {
                self::assertSame('The recovery phrase is not valid.', $e->getMessage());
            }
        }
        self::assertSame($hash, $this->user->getPasswordHash());
        self::assertTrue($this->user->hasRecovery());
    }

    public function testWithoutAPhraseThereIsNothingToRecoverWith(): void
    {
        $this->expectException(InvalidRecoveryException::class);

        $this->service->reset($this->user, RecoveryPhrase::generate()->toString(), self::NEW);
    }

    public function testAWeakNewPasswordDoesNotBurnThePhrase(): void
    {
        $phrase = $this->enable();

        try {
            $this->service->reset($this->user, $phrase->toString(), 'short');
            self::fail('A weak password must be refused.');
        } catch (PasswordPolicyException) {
            self::assertTrue($this->user->hasRecovery());
        }

        $this->service->reset($this->user, $phrase->toString(), self::NEW);
        self::assertFalse($this->user->hasRecovery());
    }

    public function testChangingThePasswordInvalidatesThePhrase(): void
    {
        $phrase = $this->enable();

        $this->changer->change($this->user, self::OLD, self::NEW);

        self::assertFalse($this->user->hasRecovery());
        $this->expectException(InvalidRecoveryException::class);
        $this->service->reset($this->user, $phrase->toString(), 'yet another passphrase');
    }

    public function testDisablingRemovesIt(): void
    {
        $phrase = $this->enable();

        $this->service->disable($this->user);

        self::assertFalse($this->user->hasRecovery());
        $this->expectException(InvalidRecoveryException::class);
        $this->service->reset($this->user, $phrase->toString(), self::NEW);
    }

    public function testANewPhraseReplacesTheOldOne(): void
    {
        $first = $this->enable();
        $second = $this->enable();

        self::assertNotSame($first->toString(), $second->toString());
        $this->expectException(InvalidRecoveryException::class);
        $this->service->reset($this->user, $first->toString(), self::NEW);
    }
}

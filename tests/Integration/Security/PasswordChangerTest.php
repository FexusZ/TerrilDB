<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\ApiToken\ApiTokenService;
use App\ApiToken\Exception\InvalidApiTokenException;
use App\Connection\ConnectionManager;
use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Crypto\DerivedKey;
use App\Crypto\Exception\DecryptionFailedException;
use App\Crypto\KeyDerivation;
use App\Crypto\SecretCipher;
use App\Crypto\SecretRotator;
use App\Crypto\SessionKeyWrapper;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Repository\DatabaseConnectionRepository;
use App\Repository\UserRepository;
use App\Security\AuthPasswordHasher;
use App\Security\Exception\InvalidCredentialsException;
use App\Security\Exception\PasswordPolicyException;
use App\Security\LoginService;
use App\Security\PasswordChanger;
use App\Security\PasswordPolicy;
use App\Security\RecoveryCodeService;
use App\Security\TotpEnrollment;
use App\Security\TotpSecretContext;
use App\Security\TotpService;
use App\Security\UserRegistrar;
use App\Tests\Integration\Persistence\DatabaseTestCase;
use OTPHP\TOTP;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

#[Group('integration')]
final class PasswordChangerTest extends DatabaseTestCase
{
    private const OLD = 'correct horse battery';
    private const NEW = 'a brand new passphrase';

    private UserRepository $repository;
    private AuthPasswordHasher $hasher;
    private KeyDerivation $derivation;
    private MockClock $clock;
    private DatabaseConnectionRepository $connections;
    private ConnectionManager $manager;
    private ApiTokenService $apiTokens;
    private PasswordChanger $changer;
    private LoginService $login;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);
        $this->repository = $repository;
        $connections = self::getContainer()->get(DatabaseConnectionRepository::class);
        self::assertInstanceOf(DatabaseConnectionRepository::class, $connections);
        $this->connections = $connections;

        $this->hasher = new AuthPasswordHasher(opsLimit: 3, memLimit: 16384);
        $this->derivation = new KeyDerivation(opsLimit: 1, memLimit: 8192);
        $this->clock = new MockClock('2026-10-29 12:00:00 UTC');
        $this->manager = new ConnectionManager(new SecretCipher(), $this->connections, $this->clock);
        $tokenRepository = self::getContainer()->get(ApiTokenRepository::class);
        self::assertInstanceOf(ApiTokenRepository::class, $tokenRepository);
        $this->apiTokens = new ApiTokenService(new SessionKeyWrapper(), $tokenRepository, $this->manager, $this->clock);
        $this->changer = new PasswordChanger(
            new PasswordPolicy(),
            $this->hasher,
            new SecretRotator($this->derivation, new SecretCipher()),
            $this->repository,
            $this->connections,
            $this->apiTokens,
        );
        $this->login = new LoginService(
            $this->hasher,
            $this->derivation,
            $this->repository,
            new TotpService(),
            new SecretCipher(),
            new RecoveryCodeService(),
            $this->clock,
            new NullLogger(),
        );

        $this->user = (new UserRegistrar(new PasswordPolicy(), $this->hasher, $this->derivation, $this->repository, $this->clock))
            ->register('alice@example.test', 'alice', self::OLD);
    }

    /**
     * @return array{non-empty-string, list<string>} le secret TOTP et les hash des codes de récupération
     */
    private function enableTotp(): array
    {
        $enrollment = new TotpEnrollment(
            new TotpService(),
            new SecretCipher(),
            new RecoveryCodeService(),
            $this->hasher,
            $this->repository,
            $this->clock,
        );
        $key = $this->derivation->derive(self::OLD, $this->user->getKdfParameters());
        $pending = $enrollment->begin($this->user);
        $enrollment->confirm($this->user, $key, $pending->secret, $this->codeFor($pending->secret));
        $this->clock->sleep(30);

        return [$pending->secret, $this->user->getRecoveryCodeHashes()];
    }

    /**
     * @param non-empty-string $secret
     */
    private function codeFor(string $secret): string
    {
        return TOTP::createFromSecret($secret)->at(max(0, $this->clock->now()->getTimestamp()));
    }

    public function testTheNewPasswordReplacesTheOldOne(): void
    {
        $this->changer->change($this->user, self::OLD, self::NEW);
        $this->entityManager->clear();

        self::assertSame('alice', $this->login->authenticate('alice', self::NEW)->user->getUsername());
        $this->expectException(InvalidCredentialsException::class);
        $this->login->authenticate('alice', self::OLD);
    }

    public function testTheKeyIsDerivedAnewFromTheNewPasswordWithAFreshSalt(): void
    {
        $oldParameters = $this->user->getKdfParameters();
        $oldKey = $this->derivation->derive(self::OLD, $oldParameters);

        $newKey = $this->changer->change($this->user, self::OLD, self::NEW);

        $parameters = $this->user->getKdfParameters();
        self::assertNotSame($oldParameters->salt, $parameters->salt);
        self::assertSame($this->derivation->derive(self::NEW, $parameters)->bytes(), $newKey->bytes());
        self::assertNotSame($oldKey->bytes(), $newKey->bytes());
    }

    public function testTheAuthenticationHashIsReplaced(): void
    {
        $oldHash = $this->user->getPasswordHash();

        $this->changer->change($this->user, self::OLD, self::NEW);

        self::assertNotSame($oldHash, $this->user->getPasswordHash());
        self::assertTrue($this->hasher->verify($this->user->getPasswordHash(), self::NEW));
        self::assertFalse($this->hasher->verify($this->user->getPasswordHash(), self::OLD));
    }

    public function testAWrongCurrentPasswordChangesNothing(): void
    {
        $hash = $this->user->getPasswordHash();

        try {
            $this->changer->change($this->user, 'not the password!!', self::NEW);
            self::fail('Expected a refusal.');
        } catch (InvalidCredentialsException) {
            $this->entityManager->clear();
            self::assertSame($hash, $this->repository->findByLogin('alice')?->getPasswordHash());
        }
    }

    public function testAWeakNewPasswordChangesNothing(): void
    {
        $hash = $this->user->getPasswordHash();

        try {
            $this->changer->change($this->user, self::OLD, 'too short');
            self::fail('Expected a policy violation.');
        } catch (PasswordPolicyException) {
            $this->entityManager->clear();
            self::assertSame($hash, $this->repository->findByLogin('alice')?->getPasswordHash());
            self::assertSame('alice', $this->login->authenticate('alice', self::OLD)->user->getUsername());
        }
    }

    public function testTheTotpSecretFollowsTheNewKey(): void
    {
        [$secret, $recoveryHashes] = $this->enableTotp();
        $oldBlob = $this->user->getTotpSecret();
        $stepBefore = $this->user->getTotpLastStep();

        $this->changer->change($this->user, self::OLD, self::NEW);
        $this->entityManager->clear();

        $user = $this->repository->findByLogin('alice');
        self::assertNotNull($user);
        $blob = $user->getTotpSecret();
        self::assertNotNull($blob);
        self::assertNotSame($oldBlob, $blob);
        $newKey = $this->derivation->derive(self::NEW, $user->getKdfParameters());
        self::assertSame($secret, (new SecretCipher())->decrypt($blob, $newKey, TotpSecretContext::forUser($user)));
        self::assertSame($recoveryHashes, $user->getRecoveryCodeHashes());
        self::assertSame($stepBefore, $user->getTotpLastStep());
    }

    public function testLoginWithTheNewPasswordAndTheSameAuthenticatorStillWorks(): void
    {
        [$secret] = $this->enableTotp();
        $this->changer->change($this->user, self::OLD, self::NEW);
        $this->entityManager->clear();

        $result = $this->login->authenticate('alice', self::NEW, $this->codeFor($secret));

        self::assertSame('alice', $result->user->getUsername());
    }

    private function oldKey(): DerivedKey
    {
        return $this->derivation->derive(self::OLD, $this->user->getKdfParameters());
    }

    private function parameters(string $password): ConnectionParameters
    {
        return new ConnectionParameters('db.internal', 5432, 'shop', 'reader', $password);
    }

    public function testConnectionsFollowTheNewKey(): void
    {
        $production = $this->manager->create($this->user, $this->oldKey(), 'Production', Engine::PostgreSql, $this->parameters('first-secret'));
        $staging = $this->manager->create($this->user, $this->oldKey(), 'Staging', Engine::MySql, $this->parameters('second-secret'));

        $newKey = $this->changer->change($this->user, self::OLD, self::NEW);
        $this->entityManager->clear();
        $user = $this->repository->findByLogin('alice');
        self::assertNotNull($user);

        self::assertSame('first-secret', $this->manager->reveal($user, $newKey, $production->getId())->password());
        self::assertSame('second-secret', $this->manager->reveal($user, $newKey, $staging->getId())->password());
    }

    public function testTheOldKeyNoLongerOpensTheConnections(): void
    {
        $oldKey = $this->oldKey();
        $connection = $this->manager->create($this->user, $oldKey, 'Production', Engine::PostgreSql, $this->parameters('first-secret'));

        $this->changer->change($this->user, self::OLD, self::NEW);
        $this->entityManager->clear();
        $user = $this->repository->findByLogin('alice');
        self::assertNotNull($user);

        $this->expectException(DecryptionFailedException::class);
        $this->manager->reveal($user, $oldKey, $connection->getId());
    }

    public function testRotatingDoesNotCountAsEditingAConnection(): void
    {
        $connection = $this->manager->create($this->user, $this->oldKey(), 'Production', Engine::PostgreSql, $this->parameters('first-secret'));
        $this->clock->sleep(3600);

        $this->changer->change($this->user, self::OLD, self::NEW);
        $this->entityManager->clear();

        $found = $this->connections->findOwned($this->user, $connection->getId());
        self::assertNotNull($found);
        self::assertEquals($connection->getCreatedAt(), $found->getUpdatedAt());
    }

    public function testAConnectionThatCannotBeDecryptedBlocksTheWholeChange(): void
    {
        $good = $this->manager->create($this->user, $this->oldKey(), 'Good', Engine::PostgreSql, $this->parameters('first-secret'));
        $broken = $this->manager->create($this->user, $this->oldKey(), 'Broken', Engine::PostgreSql, $this->parameters('second-secret'));
        $broken->rotateEncryptedParameters($good->getEncryptedParameters()); // chiffré lié à une autre connexion
        $this->connections->save($broken);
        $hash = $this->user->getPasswordHash();
        $blob = $good->getEncryptedParameters();

        try {
            $this->changer->change($this->user, self::OLD, self::NEW);
            self::fail('Expected the rotation to be refused.');
        } catch (DecryptionFailedException) {
            $this->entityManager->clear();
            $user = $this->repository->findByLogin('alice');
            self::assertNotNull($user);
            self::assertSame($hash, $user->getPasswordHash());
            self::assertSame($blob, $this->connections->findOwned($user, $good->getId())?->getEncryptedParameters());
            self::assertSame('first-secret', $this->manager->reveal($user, $this->derivation->derive(self::OLD, $user->getKdfParameters()), $good->getId())->password());
        }
    }

    public function testAWrongCurrentPasswordLeavesTheConnectionsUntouched(): void
    {
        $connection = $this->manager->create($this->user, $this->oldKey(), 'Production', Engine::PostgreSql, $this->parameters('first-secret'));
        $blob = $connection->getEncryptedParameters();

        try {
            $this->changer->change($this->user, 'not the password!!', self::NEW);
            self::fail('Expected a refusal.');
        } catch (InvalidCredentialsException) {
            $this->entityManager->clear();
            self::assertSame($blob, $this->connections->findOwned($this->user, $connection->getId())?->getEncryptedParameters());
        }
    }

    public function testOtherUsersConnectionsAreNotTouched(): void
    {
        $bob = (new UserRegistrar(new PasswordPolicy(), $this->hasher, $this->derivation, $this->repository, $this->clock))
            ->register('bob@example.test', 'bob', 'bob has a long password');
        $bobKey = $this->derivation->derive('bob has a long password', $bob->getKdfParameters());
        $theirs = $this->manager->create($bob, $bobKey, 'Theirs', Engine::PostgreSql, $this->parameters('bob-secret'));
        $blob = $theirs->getEncryptedParameters();

        $this->changer->change($this->user, self::OLD, self::NEW);
        $this->entityManager->clear();

        self::assertSame($blob, $this->connections->findOwned($bob, $theirs->getId())?->getEncryptedParameters());
    }

    public function testTotpAndConnectionsAreRotatedTogether(): void
    {
        [$secret] = $this->enableTotp();
        $connection = $this->manager->create($this->user, $this->oldKey(), 'Production', Engine::PostgreSql, $this->parameters('first-secret'));

        $newKey = $this->changer->change($this->user, self::OLD, self::NEW);
        $this->entityManager->clear();
        $user = $this->repository->findByLogin('alice');
        self::assertNotNull($user);

        self::assertSame('first-secret', $this->manager->reveal($user, $newKey, $connection->getId())->password());
        self::assertSame('alice', $this->login->authenticate('alice', self::NEW, $this->codeFor($secret))->user->getUsername());
    }

    public function testApiTokensDoNotSurviveAPasswordChange(): void
    {
        $connection = $this->manager->create($this->user, $this->oldKey(), 'Production', Engine::PostgreSql, $this->parameters('first-secret'));
        $issued = $this->apiTokens->issue($this->user, $this->oldKey(), 'Assistant', [$connection->getId()], 30);
        $this->apiTokens->authenticate($issued->token);

        $this->changer->change($this->user, self::OLD, self::NEW);
        $this->entityManager->clear();

        $this->expectException(InvalidApiTokenException::class);
        $this->apiTokens->authenticate($issued->token);
    }

    public function testAFailedChangeKeepsTheApiTokens(): void
    {
        $connection = $this->manager->create($this->user, $this->oldKey(), 'Production', Engine::PostgreSql, $this->parameters('first-secret'));
        $issued = $this->apiTokens->issue($this->user, $this->oldKey(), 'Assistant', [$connection->getId()], 30);

        try {
            $this->changer->change($this->user, 'not the password!!', self::NEW);
            self::fail('Expected a refusal.');
        } catch (InvalidCredentialsException) {
            $this->entityManager->clear();
            self::assertSame($this->user->getId()->toRfc4122(), $this->apiTokens->authenticate($issued->token)->user->getId()->toRfc4122());
        }
    }

    public function testOtherUsersTokensSurvive(): void
    {
        $bob = (new UserRegistrar(new PasswordPolicy(), $this->hasher, $this->derivation, $this->repository, $this->clock))
            ->register('bob@example.test', 'bob', 'bob has a long password');
        $bobKey = $this->derivation->derive('bob has a long password', $bob->getKdfParameters());
        $theirs = $this->manager->create($bob, $bobKey, 'Theirs', Engine::PostgreSql, $this->parameters('bob-secret'));
        $issued = $this->apiTokens->issue($bob, $bobKey, 'Bobs', [$theirs->getId()], 30);

        $this->changer->change($this->user, self::OLD, self::NEW);
        $this->entityManager->clear();

        self::assertSame('bob', $this->apiTokens->authenticate($issued->token)->user->getUsername());
    }
}

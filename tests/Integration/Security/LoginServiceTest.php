<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\Crypto\KeyDerivation;
use App\Crypto\SecretCipher;
use App\Repository\UserRepository;
use App\Security\AuthPasswordHasher;
use App\Security\Exception\InvalidCredentialsException;
use App\Security\Exception\InvalidSecondFactorException;
use App\Security\Exception\SecondFactorRequiredException;
use App\Security\LoginService;
use App\Security\NewRecoveryCodes;
use App\Security\PasswordPolicy;
use App\Security\RecoveryCodeService;
use App\Security\TotpEnrollment;
use App\Security\TotpService;
use App\Security\UserRegistrar;
use App\Tests\Integration\Persistence\DatabaseTestCase;
use OTPHP\TOTP;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

#[Group('integration')]
final class LoginServiceTest extends DatabaseTestCase
{
    private const PASSWORD = 'correct horse battery';

    private UserRepository $repository;
    private AuthPasswordHasher $hasher;
    private KeyDerivation $derivation;
    private LoginService $login;
    private TotpEnrollment $enrollment;
    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);
        $this->repository = $repository;

        // Coûts réduits : la suite vérifie le comportement, pas la dureté d'Argon2id.
        $this->hasher = new AuthPasswordHasher(opsLimit: 3, memLimit: 16384);
        $this->derivation = new KeyDerivation(opsLimit: 1, memLimit: 8192);
        $this->clock = new MockClock('2026-10-29 12:00:00 UTC');
        $this->login = $this->loginService($this->hasher);
        $this->enrollment = new TotpEnrollment(
            new TotpService(),
            new SecretCipher(),
            new RecoveryCodeService(),
            $this->hasher,
            $this->repository,
            $this->clock,
        );

        (new UserRegistrar(
            new PasswordPolicy(),
            $this->hasher,
            $this->derivation,
            $this->repository,
            $this->clock,
        ))->register('alice@example.test', 'alice', self::PASSWORD);
        $this->entityManager->clear();
    }

    private function loginService(AuthPasswordHasher $hasher): LoginService
    {
        return new LoginService(
            $hasher,
            $this->derivation,
            $this->repository,
            new TotpService(),
            new SecretCipher(),
            new RecoveryCodeService(),
            $this->clock,
            new NullLogger(),
        );
    }

    /**
     * Enrôle le TOTP d'alice et avance l'horloge d'une période : le code de confirmation est déjà consommé.
     *
     * @return array{non-empty-string, NewRecoveryCodes}
     */
    private function enrollTotp(bool $advanceTheClock = true): array
    {
        $user = $this->repository->findByLogin('alice');
        self::assertNotNull($user);
        $key = $this->derivation->derive(self::PASSWORD, $user->getKdfParameters());
        $pending = $this->enrollment->begin($user);
        $codes = $this->enrollment->confirm($user, $key, $pending->secret, $this->currentCode($pending->secret));
        if ($advanceTheClock) {
            $this->clock->sleep(30);
        }
        $this->entityManager->clear();

        return [$pending->secret, $codes];
    }

    /**
     * @param non-empty-string $secret
     */
    private function currentCode(string $secret): string
    {
        return TOTP::createFromSecret($secret)->at(max(0, $this->clock->now()->getTimestamp()));
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
        $login = $this->loginService($stronger);
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

    public function testLogsInWithAValidTotpCode(): void
    {
        [$secret] = $this->enrollTotp();

        $result = $this->login->authenticate('alice', self::PASSWORD, $this->currentCode($secret));

        self::assertSame('alice', $result->user->getUsername());
        self::assertFalse($result->usedRecoveryCode);
        $independentKey = $this->derivation->derive(self::PASSWORD, $result->user->getKdfParameters());
        self::assertSame($independentKey->bytes(), $result->key->bytes());
    }

    public function testSpacesInTheCodeAreTolerated(): void
    {
        [$secret] = $this->enrollTotp();
        $code = $this->currentCode($secret);

        $result = $this->login->authenticate('alice', self::PASSWORD, substr($code, 0, 3).' '.substr($code, 3));

        self::assertSame('alice', $result->user->getUsername());
    }

    public function testACodeCannotBeUsedTwice(): void
    {
        [$secret] = $this->enrollTotp();
        $code = $this->currentCode($secret);
        $this->login->authenticate('alice', self::PASSWORD, $code);
        $this->entityManager->clear();

        $this->expectException(InvalidSecondFactorException::class);

        $this->login->authenticate('alice', self::PASSWORD, $code);
    }

    public function testTheEnrolmentConfirmationCodeCannotLogIn(): void
    {
        [$secret] = $this->enrollTotp(advanceTheClock: false);

        $this->expectException(InvalidSecondFactorException::class);

        $this->login->authenticate('alice', self::PASSWORD, $this->currentCode($secret));
    }

    public function testAWrongCodeIsRefusedAndKeepsTheStepAvailable(): void
    {
        [$secret] = $this->enrollTotp();
        $stepBefore = $this->repository->findByLogin('alice')?->getTotpLastStep();
        $this->entityManager->clear();

        try {
            $this->login->authenticate('alice', self::PASSWORD, '000000');
            self::fail('Expected a refusal.');
        } catch (InvalidSecondFactorException) {
            $this->entityManager->clear();
            self::assertSame($stepBefore, $this->repository->findByLogin('alice')?->getTotpLastStep());
        }

        self::assertSame('alice', $this->login->authenticate('alice', self::PASSWORD, $this->currentCode($secret))->user->getUsername());
    }

    public function testAMissingOrBlankCodeAsksForTheSecondFactor(): void
    {
        $this->enrollTotp();

        foreach ([null, '', '   '] as $code) {
            try {
                $this->login->authenticate('alice', self::PASSWORD, $code);
                self::fail('Expected the second factor to be requested.');
            } catch (SecondFactorRequiredException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheSecondFactorNeverReplacesThePassword(): void
    {
        [$secret] = $this->enrollTotp();

        $this->expectException(InvalidCredentialsException::class);

        $this->login->authenticate('alice', 'another password entirely', $this->currentCode($secret));
    }

    public function testARecoveryCodeLogsInOnceAndIsConsumed(): void
    {
        [, $recovery] = $this->enrollTotp();

        $result = $this->login->authenticate('alice', self::PASSWORD, $recovery->codes[2]);

        self::assertTrue($result->usedRecoveryCode);
        self::assertCount(9, $result->user->getRecoveryCodeHashes());
        $this->entityManager->clear();

        $this->expectException(InvalidSecondFactorException::class);
        $this->login->authenticate('alice', self::PASSWORD, $recovery->codes[2]);
    }

    public function testARecoveryCodeDoesNotSpendTheTotpStep(): void
    {
        [$secret, $recovery] = $this->enrollTotp();
        $this->login->authenticate('alice', self::PASSWORD, $recovery->codes[0]);
        $this->entityManager->clear();

        self::assertFalse($this->login->authenticate('alice', self::PASSWORD, $this->currentCode($secret))->usedRecoveryCode);
    }
}

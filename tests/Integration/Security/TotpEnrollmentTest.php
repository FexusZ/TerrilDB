<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\Crypto\DerivedKey;
use App\Crypto\KeyDerivation;
use App\Crypto\SecretCipher;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\AuthPasswordHasher;
use App\Security\Exception\InvalidCredentialsException;
use App\Security\Exception\InvalidSecondFactorException;
use App\Security\Exception\TotpAlreadyEnabledException;
use App\Security\PasswordPolicy;
use App\Security\RecoveryCodeService;
use App\Security\TotpEnrollment;
use App\Security\TotpSecretContext;
use App\Security\TotpService;
use App\Security\UserRegistrar;
use App\Tests\Integration\Persistence\DatabaseTestCase;
use OTPHP\TOTP;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;

#[Group('integration')]
final class TotpEnrollmentTest extends DatabaseTestCase
{
    private const PASSWORD = 'correct horse battery';
    private const NOW = '2026-10-29 12:00:00 UTC';

    private UserRepository $repository;
    private AuthPasswordHasher $hasher;
    private KeyDerivation $derivation;
    private TotpEnrollment $enrollment;
    private User $user;
    private DerivedKey $key;

    protected function setUp(): void
    {
        parent::setUp();

        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);
        $this->repository = $repository;

        $this->hasher = new AuthPasswordHasher(opsLimit: 3, memLimit: 16384);
        $this->derivation = new KeyDerivation(opsLimit: 1, memLimit: 8192);
        $clock = new MockClock(self::NOW);
        $this->enrollment = new TotpEnrollment(
            new TotpService(),
            new SecretCipher(),
            new RecoveryCodeService(),
            $this->hasher,
            $this->repository,
            $clock,
        );

        $this->user = (new UserRegistrar(new PasswordPolicy(), $this->hasher, $this->derivation, $this->repository, $clock))
            ->register('alice@example.test', 'alice', self::PASSWORD);
        $this->key = $this->derivation->derive(self::PASSWORD, $this->user->getKdfParameters());
    }

    /**
     * @param non-empty-string $secret
     */
    private function codeFor(string $secret, string $at = self::NOW): string
    {
        return TOTP::createFromSecret($secret)->at(max(0, (new \DateTimeImmutable($at))->getTimestamp()));
    }

    private function reload(): User
    {
        $this->entityManager->clear();
        $user = $this->repository->findByLogin('alice');
        self::assertNotNull($user);

        return $user;
    }

    public function testBeginProvidesASecretAndAnEnrolmentUriWithoutStoringAnything(): void
    {
        $pending = $this->enrollment->begin($this->user);

        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $pending->secret);
        self::assertStringStartsWith('otpauth://totp/', $pending->provisioningUri);
        self::assertStringContainsString('alice', $pending->provisioningUri);
        self::assertStringContainsString('secret='.$pending->secret, $pending->provisioningUri);
        self::assertFalse($this->reload()->isTotpEnabled());
    }

    public function testConfirmingWithAValidCodeEnablesTotpAndEncryptsTheSecret(): void
    {
        $pending = $this->enrollment->begin($this->user);

        $this->enrollment->confirm($this->user, $this->key, $pending->secret, $this->codeFor($pending->secret));

        $user = $this->reload();
        self::assertTrue($user->isTotpEnabled());
        $blob = $user->getTotpSecret();
        self::assertNotNull($blob);
        self::assertStringNotContainsString($pending->secret, $blob);
        $key = $this->derivation->derive(self::PASSWORD, $user->getKdfParameters());
        self::assertSame($pending->secret, (new SecretCipher())->decrypt($blob, $key, TotpSecretContext::forUser($user)));
    }

    public function testConfirmationReturnsUsableRecoveryCodesAndStoresOnlyTheirHashes(): void
    {
        $pending = $this->enrollment->begin($this->user);

        $codes = $this->enrollment->confirm($this->user, $this->key, $pending->secret, $this->codeFor($pending->secret));

        $user = $this->reload();
        self::assertCount(10, $codes->codes);
        self::assertSame($codes->hashes, $user->getRecoveryCodeHashes());
        self::assertNotNull((new RecoveryCodeService())->consume($codes->codes[0], $user->getRecoveryCodeHashes()));
    }

    public function testTheConfirmationCodeCannotBeReplayedAtLogin(): void
    {
        $pending = $this->enrollment->begin($this->user);
        $code = $this->codeFor($pending->secret);

        $this->enrollment->confirm($this->user, $this->key, $pending->secret, $code);

        $user = $this->reload();
        self::assertGreaterThan(0, $user->getTotpLastStep());
        self::assertNull((new TotpService())->verify($pending->secret, $code, $user->getTotpLastStep(), (new \DateTimeImmutable(self::NOW))->getTimestamp()));
    }

    public function testAWrongCodeEnablesNothing(): void
    {
        $pending = $this->enrollment->begin($this->user);

        try {
            $this->enrollment->confirm($this->user, $this->key, $pending->secret, '000000');
            self::fail('Expected a refusal.');
        } catch (InvalidSecondFactorException) {
            self::assertFalse($this->reload()->isTotpEnabled());
        }
    }

    public function testACodeFromAnotherTimeIsRefused(): void
    {
        $pending = $this->enrollment->begin($this->user);

        $this->expectException(InvalidSecondFactorException::class);

        $this->enrollment->confirm($this->user, $this->key, $pending->secret, $this->codeFor($pending->secret, '2026-10-29 11:00:00 UTC'));
    }

    public function testTotpCannotBeEnrolledTwice(): void
    {
        $pending = $this->enrollment->begin($this->user);
        $this->enrollment->confirm($this->user, $this->key, $pending->secret, $this->codeFor($pending->secret));

        $this->expectException(TotpAlreadyEnabledException::class);

        $this->enrollment->begin($this->user);
    }

    public function testConfirmAlsoRefusesAnAlreadyEnabledAccount(): void
    {
        $pending = $this->enrollment->begin($this->user);
        $this->enrollment->confirm($this->user, $this->key, $pending->secret, $this->codeFor($pending->secret));
        $other = (new TotpService())->generateSecret();

        $this->expectException(TotpAlreadyEnabledException::class);

        $this->enrollment->confirm($this->user, $this->key, $other, $this->codeFor($other));
    }

    public function testDisablingRequiresThePassword(): void
    {
        $pending = $this->enrollment->begin($this->user);
        $this->enrollment->confirm($this->user, $this->key, $pending->secret, $this->codeFor($pending->secret));

        try {
            $this->enrollment->disable($this->user, 'not the password!!');
            self::fail('Expected a refusal.');
        } catch (InvalidCredentialsException) {
            self::assertTrue($this->reload()->isTotpEnabled());
        }
    }

    public function testDisablingClearsTotpAndRecoveryCodes(): void
    {
        $pending = $this->enrollment->begin($this->user);
        $this->enrollment->confirm($this->user, $this->key, $pending->secret, $this->codeFor($pending->secret));

        $this->enrollment->disable($this->user, self::PASSWORD);

        $user = $this->reload();
        self::assertFalse($user->isTotpEnabled());
        self::assertSame([], $user->getRecoveryCodeHashes());
        self::assertSame(0, $user->getTotpLastStep());
    }

    public function testTheSecretContextIsBoundToTheUser(): void
    {
        $other = (new UserRegistrar(
            new PasswordPolicy(),
            $this->hasher,
            $this->derivation,
            $this->repository,
            new MockClock(self::NOW),
        ))->register('bob@example.test', 'bob', self::PASSWORD);

        self::assertNotSame(TotpSecretContext::forUser($this->user), TotpSecretContext::forUser($other));
        self::assertSame(TotpSecretContext::forUser($this->user), TotpSecretContext::forUser($this->user));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Crypto;

use App\Crypto\Exception\DecryptionFailedException;
use App\Crypto\Exception\EmptyPasswordException;
use App\Crypto\KdfParameters;
use App\Crypto\KeyDerivation;
use App\Crypto\SecretCipher;
use App\Crypto\SecretRotator;
use PHPUnit\Framework\TestCase;

final class SecretRotatorTest extends TestCase
{
    private KeyDerivation $derivation;
    private SecretCipher $cipher;
    private SecretRotator $rotator;

    protected function setUp(): void
    {
        $this->derivation = new KeyDerivation(opsLimit: 1, memLimit: 8192);
        $this->cipher = new SecretCipher();
        $this->rotator = new SecretRotator($this->derivation, $this->cipher);
    }

    /**
     * @param array<array-key, string> $plaintexts
     *
     * @return array{KdfParameters, array<array-key, string>}
     */
    private function enroll(string $password, array $plaintexts): array
    {
        $parameters = $this->derivation->newParameters();
        $key = $this->derivation->derive($password, $parameters);

        $secrets = [];
        foreach ($plaintexts as $id => $plaintext) {
            $secrets[$id] = $this->cipher->encrypt($plaintext, $key, (string) $id);
        }

        return [$parameters, $secrets];
    }

    public function testSecretsOpenWithTheNewPasswordAndKeepTheirIdentifiers(): void
    {
        [$parameters, $secrets] = $this->enroll('old password', ['conn-1' => 'user:pass-1', 'conn-2' => 'user:pass-2']);

        $result = $this->rotator->rotate('old password', $parameters, 'new password', $secrets);

        $newKey = $this->derivation->derive('new password', $result->parameters);
        self::assertSame(['conn-1', 'conn-2'], array_keys($result->secrets));
        self::assertSame('user:pass-1', $this->cipher->decrypt($result->secrets['conn-1'], $newKey, 'conn-1'));
        self::assertSame('user:pass-2', $this->cipher->decrypt($result->secrets['conn-2'], $newKey, 'conn-2'));
    }

    public function testNumericIdentifiersSurviveAsContext(): void
    {
        [$parameters, $secrets] = $this->enroll('old password', [42 => 'user:pass']);

        $result = $this->rotator->rotate('old password', $parameters, 'new password', $secrets);

        $newKey = $this->derivation->derive('new password', $result->parameters);
        self::assertSame('user:pass', $this->cipher->decrypt($result->secrets[42], $newKey, '42'));
    }

    public function testOldPasswordNoLongerOpensTheSecrets(): void
    {
        [$parameters, $secrets] = $this->enroll('old password', ['conn-1' => 'user:pass']);

        $result = $this->rotator->rotate('old password', $parameters, 'new password', $secrets);

        $oldKey = $this->derivation->derive('old password', $parameters);
        $this->expectException(DecryptionFailedException::class);
        $this->cipher->decrypt($result->secrets['conn-1'], $oldKey, 'conn-1');
    }

    public function testRotationUsesFreshParametersAndHandsBackTheNewKey(): void
    {
        [$parameters, $secrets] = $this->enroll('old password', ['conn-1' => 'user:pass']);

        $result = $this->rotator->rotate('old password', $parameters, 'new password', $secrets);

        self::assertNotSame($parameters->salt, $result->parameters->salt);
        self::assertFalse($result->key->isWiped());
        self::assertTrue(hash_equals(
            $this->derivation->derive('new password', $result->parameters)->bytes(),
            $result->key->bytes(),
        ));
    }

    public function testCiphertextsAreFresh(): void
    {
        [$parameters, $secrets] = $this->enroll('same password', ['conn-1' => 'user:pass']);

        $result = $this->rotator->rotate('same password', $parameters, 'same password', $secrets);

        self::assertNotSame($secrets['conn-1'], $result->secrets['conn-1']);
    }

    public function testWrongOldPasswordFailsWithoutProducingAnything(): void
    {
        [$parameters, $secrets] = $this->enroll('old password', ['conn-1' => 'user:pass']);

        $this->expectException(DecryptionFailedException::class);
        $this->rotator->rotate('wrong password', $parameters, 'new password', $secrets);
    }

    public function testOneUndecryptableSecretAbortsTheWholeRotation(): void
    {
        [$parameters, $secrets] = $this->enroll('old password', ['conn-1' => 'user:pass-1', 'conn-2' => 'user:pass-2']);
        $secrets['conn-2'] = $this->cipher->encrypt('forged', $this->derivation->derive('other', $parameters), 'conn-2');

        $this->expectException(DecryptionFailedException::class);
        $this->rotator->rotate('old password', $parameters, 'new password', $secrets);
    }

    public function testSecretMovedToAnotherIdentifierAbortsTheRotation(): void
    {
        [$parameters, $secrets] = $this->enroll('old password', ['conn-1' => 'user:pass-1']);

        $this->expectException(DecryptionFailedException::class);
        $this->rotator->rotate('old password', $parameters, 'new password', ['conn-9' => $secrets['conn-1']]);
    }

    public function testRotatingWithoutSecretsStillIssuesNewParametersAndKey(): void
    {
        $parameters = $this->derivation->newParameters();

        $result = $this->rotator->rotate('old password', $parameters, 'new password', []);

        self::assertSame([], $result->secrets);
        self::assertNotSame($parameters->salt, $result->parameters->salt);
        self::assertFalse($result->key->isWiped());
    }

    public function testRejectsAnEmptyNewPassword(): void
    {
        [$parameters, $secrets] = $this->enroll('old password', ['conn-1' => 'user:pass']);

        $this->expectException(EmptyPasswordException::class);
        $this->rotator->rotate('old password', $parameters, '', $secrets);
    }

    public function testRotatingWithTheOldKeyGivesTheSameResultAsWithTheOldPassword(): void
    {
        [$parameters, $secrets] = $this->enroll('old password', ['a' => 'alpha', 'b' => 'beta']);
        $oldKey = $this->derivation->derive('old password', $parameters);

        $result = $this->rotator->rotateWithKey($oldKey, 'new password', $secrets);

        $newKey = $this->derivation->derive('new password', $result->parameters);
        self::assertSame('alpha', $this->cipher->decrypt($result->secrets['a'], $newKey, 'a'));
        self::assertSame('beta', $this->cipher->decrypt($result->secrets['b'], $newKey, 'b'));
        self::assertFalse($oldKey->isWiped(), 'the caller owns the key it passed in');
    }

    public function testRotatingWithTheWrongKeyProducesNothing(): void
    {
        [, $secrets] = $this->enroll('old password', ['a' => 'alpha']);
        $wrong = $this->derivation->derive('another password', $this->derivation->newParameters());

        $this->expectException(DecryptionFailedException::class);

        $this->rotator->rotateWithKey($wrong, 'new password', $secrets);
    }
}

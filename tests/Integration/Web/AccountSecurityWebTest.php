<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Security\PasswordChanger;
use OTPHP\TOTP;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class AccountSecurityWebTest extends WebApiTestCase
{
    private const NEW_PASSWORD = 'a brand new passphrase';

    /**
     * Un code de la période suivante : accepté (dérive d'une période tolérée) et postérieur à celui de l'activation.
     *
     * @param non-empty-string $secret
     */
    private function nextCode(string $secret): string
    {
        return TOTP::createFromSecret($secret)->at(time() + 30);
    }

    /**
     * @param array<string, mixed> $begin
     *
     * @return non-empty-string
     */
    private static function secretOf(array $begin): string
    {
        $secret = $begin['secret'] ?? null;
        if (!\is_string($secret) || '' === $secret) {
            self::fail('The response carries no secret.');
        }

        return $secret;
    }

    /**
     * @return array{non-empty-string, list<string>}
     */
    private function enableTotp(): array
    {
        $begin = $this->call('POST', '/web/account/totp/begin');
        self::assertSame(200, $this->statusCode());
        $secret = self::secretOf($begin);
        self::assertIsString($begin['uri']);
        self::assertStringStartsWith('otpauth://totp/', $begin['uri']);

        $confirmed = $this->call('POST', '/web/account/totp/confirm', ['secret' => $secret, 'code' => TOTP::createFromSecret($secret)->now()]);
        self::assertSame(200, $this->statusCode(), json_encode($confirmed, \JSON_THROW_ON_ERROR));
        self::assertIsArray($confirmed['recoveryCodes']);
        /** @var list<string> $codes */
        $codes = $confirmed['recoveryCodes'];

        return [$secret, $codes];
    }

    public function testChangingThePasswordKeepsTheCurrentSessionAndReplacesTheSecret(): void
    {
        $this->seedAndLogin();

        $body = $this->call('POST', '/web/account/password', ['current' => self::PASSWORD, 'new' => self::NEW_PASSWORD]);
        self::assertSame(200, $this->statusCode());
        self::assertIsArray($body['user']);

        $this->call('GET', '/web/me');
        self::assertSame(200, $this->statusCode(), 'la session courante reste ouverte');

        $this->call('POST', '/web/logout');
        $this->call('POST', '/web/login', ['login' => 'alice', 'password' => self::PASSWORD]);
        self::assertSame(401, $this->statusCode());
        $this->login('alice', self::NEW_PASSWORD);
    }

    public function testChangingThePasswordEndsTheOtherSessions(): void
    {
        $user = $this->seedAndLogin();

        // Un autre appareil change le mot de passe : la session de celui-ci n'est plus reconnue.
        $changer = self::getContainer()->get(PasswordChanger::class);
        self::assertInstanceOf(PasswordChanger::class, $changer);
        $changer->change($user, self::PASSWORD, self::NEW_PASSWORD)->wipe();

        $this->call('GET', '/web/me');
        self::assertSame(401, $this->statusCode());
    }

    public function testAWrongCurrentPasswordOrAWeakNewOneChangesNothing(): void
    {
        $this->seedAndLogin();

        $wrong = $this->call('POST', '/web/account/password', ['current' => 'not the password!!', 'new' => self::NEW_PASSWORD]);
        self::assertSame(401, $this->statusCode());
        self::assertSame('invalid_credentials', $wrong['error']);

        $weak = $this->call('POST', '/web/account/password', ['current' => self::PASSWORD, 'new' => 'short']);
        self::assertSame(422, $this->statusCode());
        self::assertSame('weak_password', $weak['error']);

        $this->call('POST', '/web/logout');
        $this->login('alice', self::PASSWORD);
    }

    public function testTotpEnrolmentThenLoginNeedsTheCode(): void
    {
        $this->seedAndLogin();
        [$secret] = $this->enableTotp();
        $me = $this->call('GET', '/web/me');
        self::assertIsArray($me['user']);
        self::assertTrue($me['user']['totpEnabled']);
        self::assertSame(10, $me['user']['recoveryCodesLeft']);
        $this->call('POST', '/web/logout');

        $missing = $this->call('POST', '/web/login', ['login' => 'alice', 'password' => self::PASSWORD]);
        self::assertSame(401, $this->statusCode());
        self::assertSame('second_factor_required', $missing['error']);

        $wrong = $this->call('POST', '/web/login', ['login' => 'alice', 'password' => self::PASSWORD, 'code' => '000000']);
        self::assertSame(401, $this->statusCode());
        self::assertSame('invalid_second_factor', $wrong['error']);

        $this->login('alice', self::PASSWORD, $this->nextCode($secret));
    }

    public function testAWrongConfirmationCodeEnablesNothing(): void
    {
        $this->seedAndLogin();
        $secret = self::secretOf($this->call('POST', '/web/account/totp/begin'));

        $body = $this->call('POST', '/web/account/totp/confirm', ['secret' => $secret, 'code' => '000000']);
        self::assertSame(401, $this->statusCode());
        self::assertSame('invalid_second_factor', $body['error']);

        $me = $this->call('GET', '/web/me');
        self::assertIsArray($me['user']);
        self::assertFalse($me['user']['totpEnabled']);
    }

    public function testEnrollingTwiceIsRefused(): void
    {
        $this->seedAndLogin();
        $this->enableTotp();

        $body = $this->call('POST', '/web/account/totp/begin');

        self::assertSame(409, $this->statusCode());
        self::assertSame('totp_already_enabled', $body['error']);
    }

    public function testDisablingTotpNeedsThePassword(): void
    {
        $this->seedAndLogin();
        $this->enableTotp();

        $wrong = $this->call('POST', '/web/account/totp/disable', ['password' => 'not the password!!']);
        self::assertSame(401, $this->statusCode());
        self::assertSame('invalid_credentials', $wrong['error']);

        $this->call('POST', '/web/account/totp/disable', ['password' => self::PASSWORD]);
        self::assertSame(204, $this->statusCode());
        $me = $this->call('GET', '/web/me');
        self::assertIsArray($me['user']);
        self::assertFalse($me['user']['totpEnabled']);
    }

    public function testARecoveryCodeReplacesTheAppCodeOnceAndWarnsTheUser(): void
    {
        $this->seedAndLogin();
        [, $codes] = $this->enableTotp();
        $this->call('POST', '/web/logout');

        $first = $this->call('POST', '/web/login', ['login' => 'alice', 'password' => self::PASSWORD, 'code' => $codes[0]]);
        self::assertSame(200, $this->statusCode());
        self::assertTrue($first['recoveryCodeUsed']);
        $this->call('POST', '/web/logout');

        $this->call('POST', '/web/login', ['login' => 'alice', 'password' => self::PASSWORD, 'code' => $codes[0]]);
        self::assertSame(401, $this->statusCode(), 'un code de récupération ne sert qu\'une fois');
    }

    public function testRecoveryCodesCanBeRegeneratedWithThePassword(): void
    {
        $this->seedAndLogin();
        [, $codes] = $this->enableTotp();

        $wrong = $this->call('POST', '/web/account/recovery-codes', ['password' => 'not the password!!']);
        self::assertSame(401, $this->statusCode());
        self::assertSame('invalid_credentials', $wrong['error']);

        $fresh = $this->call('POST', '/web/account/recovery-codes', ['password' => self::PASSWORD]);
        self::assertSame(200, $this->statusCode());
        self::assertIsArray($fresh['recoveryCodes']);
        self::assertCount(10, $fresh['recoveryCodes']);
        self::assertSame([], array_intersect($codes, array_map(static fn (mixed $code): string => \is_string($code) ? $code : '', $fresh['recoveryCodes'])));
    }

    public function testRegeneratingWithoutASecondFactorIsRefused(): void
    {
        $this->seedAndLogin();

        $body = $this->call('POST', '/web/account/recovery-codes', ['password' => self::PASSWORD]);

        self::assertSame(409, $this->statusCode());
        self::assertSame('totp_not_enabled', $body['error']);
    }

    public function testASessionWithoutItsUnlockCookieIsLocked(): void
    {
        $this->seedAndLogin();
        $secret = self::secretOf($this->call('POST', '/web/account/totp/begin'));
        $this->client->getCookieJar()->expire('TERRILDB_UNLOCK');

        // Chiffrer le secret TOTP demande la clé de la session, que le cookie manquant rend inaccessible.
        $body = $this->call('POST', '/web/account/totp/confirm', ['secret' => $secret, 'code' => TOTP::createFromSecret($secret)->now()]);

        self::assertSame(401, $this->statusCode());
        self::assertSame('locked', $body['error']);
    }

    public function testPasswordChecksAreThrottled(): void
    {
        $this->seedAndLogin();

        for ($i = 0; $i < 8; ++$i) {
            $this->call('POST', '/web/account/password', ['current' => 'not the password!!', 'new' => self::NEW_PASSWORD]);
            self::assertSame(401, $this->statusCode());
        }
        $this->call('POST', '/web/account/password', ['current' => 'not the password!!', 'new' => self::NEW_PASSWORD]);

        self::assertSame(429, $this->statusCode());
    }
}

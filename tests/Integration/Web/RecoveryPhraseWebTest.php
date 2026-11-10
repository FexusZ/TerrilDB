<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use OTPHP\TOTP;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class RecoveryPhraseWebTest extends WebApiTestCase
{
    private const NEW = 'a brand new passphrase';
    private const FORMAT = '/^[0-9A-HJKMNP-TV-Z]{4}(-[0-9A-HJKMNP-TV-Z]{4}){7}$/D';

    private function enable(): string
    {
        $body = $this->call('POST', '/web/account/recovery-phrase', ['password' => self::PASSWORD]);
        self::assertSame(201, $this->statusCode());
        self::assertIsString($body['phrase']);

        return $body['phrase'];
    }

    public function testEnablingNeedsASessionAndThePassword(): void
    {
        $this->call('POST', '/web/account/recovery-phrase', ['password' => self::PASSWORD]);
        self::assertSame(401, $this->statusCode());

        $this->seedAndLogin();
        $body = $this->call('POST', '/web/account/recovery-phrase', ['password' => 'not the password!!']);
        self::assertSame(401, $this->statusCode());
        self::assertSame('invalid_credentials', $body['error']);
        self::assertArrayNotHasKey('phrase', $body);
    }

    public function testShowsThePhraseOnceAndRemembersThatOneExists(): void
    {
        $this->seedAndLogin();
        self::assertFalse(self::arrayOf($this->call('GET', '/web/me')['user'])['recoveryPhrase']);

        $phrase = $this->enable();

        self::assertMatchesRegularExpression(self::FORMAT, $phrase);
        $me = $this->call('GET', '/web/me');
        self::assertTrue(self::arrayOf($me['user'])['recoveryPhrase']);
        self::assertStringNotContainsString($phrase, json_encode($me, \JSON_THROW_ON_ERROR));
    }

    public function testDisablingNeedsThePassword(): void
    {
        $this->seedAndLogin();
        $this->enable();

        $this->call('POST', '/web/account/recovery-phrase/disable', ['password' => 'not the password!!']);
        self::assertSame(401, $this->statusCode());
        self::assertTrue(self::arrayOf($this->call('GET', '/web/me')['user'])['recoveryPhrase']);

        $this->call('POST', '/web/account/recovery-phrase/disable', ['password' => self::PASSWORD]);
        self::assertSame(204, $this->statusCode());
        self::assertFalse(self::arrayOf($this->call('GET', '/web/me')['user'])['recoveryPhrase']);
    }

    public function testThePhraseSetsANewPasswordWithoutBeingLoggedIn(): void
    {
        $this->seedAndLogin();
        $phrase = $this->enable();
        $this->call('POST', '/web/logout');

        $this->call('POST', '/web/recover', ['login' => 'alice', 'phrase' => strtolower($phrase), 'newPassword' => self::NEW]);

        self::assertSame(204, $this->statusCode());
        $this->call('POST', '/web/login', ['login' => 'alice', 'password' => self::PASSWORD]);
        self::assertSame(401, $this->statusCode());
        $this->login('alice', self::NEW);
        self::assertFalse(self::arrayOf($this->call('GET', '/web/me')['user'])['recoveryPhrase'], 'the used phrase is gone');
    }

    public function testAWrongPhraseAnUnknownAccountAndNoPhraseLookTheSame(): void
    {
        $this->seedAndLogin();
        $phrase = $this->enable();
        $this->seedUser('bob');
        $this->call('POST', '/web/logout');

        $wrong = $this->call('POST', '/web/recover', ['login' => 'alice', 'phrase' => '0000-0000-0000-0000-0000-0000-0000-0000', 'newPassword' => self::NEW]);
        self::assertSame(403, $this->statusCode());
        $unknown = $this->call('POST', '/web/recover', ['login' => 'nobody', 'phrase' => $phrase, 'newPassword' => self::NEW]);
        self::assertSame(403, $this->statusCode());
        $none = $this->call('POST', '/web/recover', ['login' => 'bob', 'phrase' => $phrase, 'newPassword' => self::NEW]);
        self::assertSame(403, $this->statusCode());

        self::assertSame('invalid_recovery', $wrong['error']);
        self::assertSame($wrong, $unknown);
        self::assertSame($wrong, $none);
        $this->login('alice');
    }

    public function testAWeakNewPasswordIsRefusedAndTheOldOneStillWorks(): void
    {
        $this->seedAndLogin();
        $phrase = $this->enable();
        $this->call('POST', '/web/logout');

        $body = $this->call('POST', '/web/recover', ['login' => 'alice', 'phrase' => $phrase, 'newPassword' => 'short']);

        self::assertSame(422, $this->statusCode());
        self::assertSame('weak_password', $body['error']);
        $this->login('alice');
    }

    public function testGuessingIsThrottled(): void
    {
        $this->seedAndLogin();
        $this->enable();
        $this->call('POST', '/web/logout');

        for ($i = 0; $i < 5; ++$i) {
            $this->call('POST', '/web/recover', ['login' => 'alice', 'phrase' => '0000-0000-0000-0000-0000-0000-0000-0000', 'newPassword' => self::NEW]);
            self::assertSame(403, $this->statusCode());
        }
        $body = $this->call('POST', '/web/recover', ['login' => 'alice', 'phrase' => '0000-0000-0000-0000-0000-0000-0000-0000', 'newPassword' => self::NEW]);

        self::assertSame(429, $this->statusCode());
        self::assertSame('too_many_attempts', $body['error']);
    }

    public function testTheSecondFactorIsStillRequiredAfterARecovery(): void
    {
        $this->seedAndLogin();
        $secret = $this->beginTotp();
        $this->call('POST', '/web/account/totp/confirm', ['secret' => $secret, 'code' => TOTP::createFromSecret($secret)->now()]);
        self::assertSame(200, $this->statusCode());
        $phrase = $this->enable();
        $this->call('POST', '/web/logout');

        $this->call('POST', '/web/recover', ['login' => 'alice', 'phrase' => $phrase, 'newPassword' => self::NEW]);
        self::assertSame(204, $this->statusCode());

        $body = $this->call('POST', '/web/login', ['login' => 'alice', 'password' => self::NEW]);
        self::assertSame(401, $this->statusCode());
        self::assertSame('second_factor_required', $body['error']);
    }

    public function testChangingThePasswordRetiresThePhrase(): void
    {
        $this->seedAndLogin();
        $this->enable();

        $this->call('POST', '/web/account/password', ['current' => self::PASSWORD, 'new' => self::NEW]);

        self::assertSame(200, $this->statusCode());
        self::assertFalse(self::arrayOf($this->call('GET', '/web/me')['user'])['recoveryPhrase']);
    }

    public function testEverythingIsAuditedWithoutThePhrase(): void
    {
        $user = $this->seedAndLogin();
        $phrase = $this->enable();
        $this->call('POST', '/web/logout');
        $this->call('POST', '/web/recover', ['login' => 'alice', 'phrase' => '0000-0000-0000-0000-0000-0000-0000-0000', 'newPassword' => self::NEW]);
        $this->call('POST', '/web/recover', ['login' => 'alice', 'phrase' => $phrase, 'newPassword' => self::NEW]);

        $events = $this->auditEvents($user, 'phrase');

        self::assertSame(['phrase.used', 'phrase.failed', 'phrase.created'], array_map(static fn ($event): string => $event->getAction()->value, $events));
        self::assertStringNotContainsString(str_replace('-', '', $phrase), str_replace('-', '', json_encode(array_map(static fn ($event): array => $event->getDetails(), $events), \JSON_THROW_ON_ERROR)));
    }
}

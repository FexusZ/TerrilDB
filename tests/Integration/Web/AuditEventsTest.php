<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Audit\AuditAction;
use App\Audit\AuditActor;
use App\Audit\AuditOutcome;
use App\Entity\AuditEvent;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use OTPHP\TOTP;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class AuditEventsTest extends WebApiTestCase
{
    /**
     * @return list<string>
     */
    private function actions(User $user, ?string $category = null): array
    {
        return array_map(static fn (AuditEvent $e): string => $e->getAction()->value, $this->auditEvents($user, $category));
    }

    private function last(User $user): AuditEvent
    {
        return $this->auditEvents($user)[0];
    }

    public function testRegistrationIsRecorded(): void
    {
        $body = $this->call('POST', '/web/register', ['email' => 'alice@example.test', 'username' => 'alice', 'password' => self::PASSWORD]);
        $id = self::arrayOf($body['user'])['id'];
        self::assertIsString($id);
        $users = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $users);
        $user = $users->getRepository(User::class)->find($id);
        self::assertInstanceOf(User::class, $user);

        self::assertSame(['account.registered'], $this->actions($user));
        self::assertSame(AuditActor::Web, $this->last($user)->getActor());
    }

    public function testASuccessfulLoginIsRecordedWithItsAddress(): void
    {
        $user = $this->seedAndLogin();

        $event = $this->last($user);
        self::assertSame(AuditAction::LoginSuccess, $event->getAction());
        self::assertSame(AuditOutcome::Success, $event->getOutcome());
        self::assertNotNull($event->getIp());
    }

    public function testAFailedLoginIsAttributedToTheAccountItAimedAt(): void
    {
        $user = $this->seedUser();

        $this->call('POST', '/web/login', ['login' => 'alice', 'password' => 'not the password!!']);

        $event = $this->last($user);
        self::assertSame(AuditAction::LoginFailure, $event->getAction());
        self::assertSame(AuditOutcome::Failed, $event->getOutcome());
        self::assertSame('invalid_credentials', $event->getDetails()['reason']);
        self::assertStringNotContainsString('not the password', json_encode($event->getDetails(), \JSON_THROW_ON_ERROR));
    }

    public function testAskingForTheSecondFactorIsNotAFailure(): void
    {
        $user = $this->seedAndLogin();
        $secret = $this->beginTotp();
        $this->call('POST', '/web/account/totp/confirm', ['secret' => $secret, 'code' => TOTP::createFromSecret($secret)->now()]);
        $this->call('POST', '/web/logout');
        $before = \count($this->auditEvents($user, 'login'));

        $this->call('POST', '/web/login', ['login' => 'alice', 'password' => self::PASSWORD]);
        self::assertSame(401, $this->statusCode());
        self::assertCount($before, $this->auditEvents($user, 'login'));

        $this->call('POST', '/web/login', ['login' => 'alice', 'password' => self::PASSWORD, 'code' => '000000']);
        $event = $this->last($user);
        self::assertSame(AuditAction::LoginFailure, $event->getAction());
        self::assertSame('invalid_second_factor', $event->getDetails()['reason']);
    }

    public function testThrottlingIsRecorded(): void
    {
        $user = $this->seedUser();
        for ($i = 0; $i < 9; ++$i) {
            $this->call('POST', '/web/login', ['login' => 'alice', 'password' => 'not the password!!']);
        }

        self::assertSame(429, $this->statusCode());
        self::assertContains('login.throttled', $this->actions($user, 'login'));
    }

    public function testARecoveryCodeUseIsRecorded(): void
    {
        $user = $this->seedAndLogin();
        $secret = $this->beginTotp();
        $confirmed = $this->call('POST', '/web/account/totp/confirm', ['secret' => $secret, 'code' => TOTP::createFromSecret($secret)->now()]);
        $code = self::arrayOf($confirmed['recoveryCodes'])[0];
        self::assertIsString($code);
        $this->call('POST', '/web/logout');

        $this->login('alice', self::PASSWORD, $code);

        self::assertContains('recovery.used', $this->actions($user));
        self::assertStringNotContainsString($code, json_encode(array_map(static fn (AuditEvent $e): array => $e->getDetails(), $this->auditEvents($user)), \JSON_THROW_ON_ERROR));
    }

    public function testLogoutIsRecorded(): void
    {
        $user = $this->seedAndLogin();

        $this->call('POST', '/web/logout');

        self::assertSame(AuditAction::LogoutDone, $this->last($user)->getAction());
    }

    public function testPasswordChangesAndRefusalsAreRecorded(): void
    {
        $user = $this->seedAndLogin();

        $this->call('POST', '/web/account/password', ['current' => 'not the password!!', 'new' => 'a brand new passphrase']);
        self::assertSame(AuditAction::PasswordChangeFailed, $this->last($user)->getAction());

        $this->call('POST', '/web/account/password', ['current' => self::PASSWORD, 'new' => 'a brand new passphrase']);
        $event = $this->last($user);
        self::assertSame(AuditAction::PasswordChanged, $event->getAction());
        self::assertSame(AuditOutcome::Success, $event->getOutcome());
        self::assertStringNotContainsString('passphrase', json_encode($event->getDetails(), \JSON_THROW_ON_ERROR));
    }

    public function testSecondFactorChangesAreRecorded(): void
    {
        $user = $this->seedAndLogin();
        $secret = $this->beginTotp();
        $this->call('POST', '/web/account/totp/confirm', ['secret' => $secret, 'code' => TOTP::createFromSecret($secret)->now()]);
        self::assertSame(AuditAction::TotpEnabled, $this->last($user)->getAction());

        $this->call('POST', '/web/account/recovery-codes', ['password' => self::PASSWORD]);
        self::assertSame(AuditAction::RecoveryRegenerated, $this->last($user)->getAction());

        $this->call('POST', '/web/account/totp/disable', ['password' => self::PASSWORD]);
        self::assertSame(AuditAction::TotpDisabled, $this->last($user)->getAction());
    }

    public function testConnectionChangesAreRecordedWithoutTheirParameters(): void
    {
        $user = $this->seedAndLogin();
        $body = ['name' => 'Production', 'engine' => 'postgresql', 'host' => 'db.exemple.test', 'database' => 'shop', 'username' => 'lecteur', 'password' => 'hunter2-hunter2'];
        $created = $this->call('POST', '/web/connections', $body);
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);

        $event = $this->last($user);
        self::assertSame(AuditAction::ConnectionCreated, $event->getAction());
        self::assertSame($id, $event->getSubjectId()?->toRfc4122());
        self::assertSame(['name' => 'Production', 'engine' => 'postgresql'], $event->getDetails());

        $this->call('PUT', '/web/connections/'.$id, ['name' => 'Renamed'] + $body);
        self::assertSame(AuditAction::ConnectionUpdated, $this->last($user)->getAction());

        $this->call('DELETE', '/web/connections/'.$id);
        $deleted = $this->last($user);
        self::assertSame(AuditAction::ConnectionDeleted, $deleted->getAction());
        self::assertSame($id, $deleted->getSubjectId()?->toRfc4122());
    }

    public function testTokenChangesAreRecorded(): void
    {
        $user = $this->seedAndLogin();
        $connection = self::arrayOf($this->call('POST', '/web/connections', ['name' => 'P', 'engine' => 'postgresql', 'host' => 'h', 'database' => 'd', 'username' => 'u', 'password' => 'p'])['connection'])['id'];
        self::assertIsString($connection);
        $created = $this->call('POST', '/web/tokens', ['name' => 'Assistant', 'connectionIds' => [$connection], 'lifetimeDays' => 7]);
        $tokenId = self::arrayOf($created['tokenInfo'])['id'];
        self::assertIsString($tokenId);
        self::assertIsString($created['token']);

        $event = $this->last($user);
        self::assertSame(AuditAction::TokenCreated, $event->getAction());
        self::assertSame($tokenId, $event->getSubjectId()?->toRfc4122());
        self::assertSame(['name' => 'Assistant', 'connections' => 1, 'lifetimeDays' => 7], $event->getDetails());
        self::assertStringNotContainsString(substr($created['token'], -43), json_encode($event->getDetails(), \JSON_THROW_ON_ERROR));

        $this->call('DELETE', '/web/tokens/'.$tokenId);
        self::assertSame(AuditAction::TokenRevoked, $this->last($user)->getAction());
    }

    public function testNoSecretEverEntersTheJournal(): void
    {
        $user = $this->seedAndLogin();
        $this->call('POST', '/web/connections', ['name' => 'Production', 'engine' => 'postgresql', 'host' => 'db.exemple.test', 'database' => 'shop', 'username' => 'lecteur', 'password' => 'hunter2-hunter2']);
        $this->call('POST', '/web/account/password', ['current' => self::PASSWORD, 'new' => 'a brand new passphrase']);
        $this->call('POST', '/web/login', ['login' => 'alice', 'password' => 'definitely wrong password']);

        $journal = json_encode(array_map(static fn (AuditEvent $e): array => [$e->getDetails(), $e->getIp()], $this->auditEvents($user)), \JSON_THROW_ON_ERROR);

        foreach (['hunter2', 'correct horse', 'passphrase', 'definitely wrong', 'db.exemple.test', 'lecteur'] as $secret) {
            self::assertStringNotContainsString($secret, $journal, $secret);
        }
    }
}

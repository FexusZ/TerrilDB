<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class InvitationsWebTest extends WebApiTestCase
{
    /**
     * @return array{string, string}
     */
    private function invite(string $note = 'Pour Marie'): array
    {
        $created = $this->call('POST', '/web/invitations', ['note' => $note]);
        self::assertSame(201, $this->statusCode());
        self::assertIsString($created['token']);
        $id = self::arrayOf($created['invitation'])['id'];
        self::assertIsString($id);

        return [$created['token'], $id];
    }

    public function testInvitationsRequireAnAuthenticatedSession(): void
    {
        $this->call('GET', '/web/invitations');

        self::assertSame(401, $this->statusCode());
    }

    public function testAnAdministratorCreatesAnInvitationShownOnce(): void
    {
        $this->seedAndLogin();

        [$token] = $this->invite();

        self::assertMatchesRegularExpression('/^inv_[0-9a-f]{32}_[A-Za-z0-9_-]{43}$/D', $token);
        $list = $this->call('GET', '/web/invitations');
        self::assertSame(200, $this->statusCode());
        $invitations = self::arrayOf($list['invitations']);
        self::assertCount(1, $invitations);
        self::assertSame('pending', self::arrayOf($invitations[0])['status']);
        self::assertSame('Pour Marie', self::arrayOf($invitations[0])['note']);
        self::assertStringNotContainsString(substr($token, -43), json_encode($list, \JSON_THROW_ON_ERROR));
    }

    public function testAMemberIsForbidden(): void
    {
        $this->seedUser('alice');
        $this->seedUser('bob');
        $this->login('bob');

        foreach ([['GET', '/web/invitations'], ['POST', '/web/invitations'], ['DELETE', '/web/invitations/00000000-0000-7000-8000-000000000000']] as [$method, $uri]) {
            $body = $this->call($method, $uri, 'POST' === $method ? ['note' => 'x'] : []);
            self::assertSame(403, $this->statusCode(), $method);
            self::assertSame('forbidden', $body['error']);
        }
    }

    public function testRefusesAnInvalidLifetime(): void
    {
        $this->seedAndLogin();

        $this->call('POST', '/web/invitations', ['lifetimeDays' => 90]);

        self::assertSame(422, $this->statusCode());
    }

    public function testAGuestRegistersWithTheInvitationOnceRegistrationIsClosed(): void
    {
        $this->seedAndLogin();
        [$token] = $this->invite();
        $this->call('POST', '/web/logout');

        $this->call('POST', '/web/register', ['email' => 'marie@example.test', 'username' => 'marie', 'password' => self::PASSWORD]);
        self::assertSame(403, $this->statusCode());

        $body = $this->call('POST', '/web/register', ['email' => 'marie@example.test', 'username' => 'marie', 'password' => self::PASSWORD, 'invitation' => $token]);
        self::assertSame(201, $this->statusCode());
        self::assertSame('member', self::arrayOf($body['user'])['role']);

        $again = $this->call('POST', '/web/register', ['email' => 'eve@example.test', 'username' => 'eve', 'password' => self::PASSWORD, 'invitation' => $token]);
        self::assertSame(403, $this->statusCode());
        self::assertSame('invalid_invitation', $again['error']);

        $this->login('alice');
        $list = $this->call('GET', '/web/invitations');
        self::assertSame('used', self::arrayOf(self::arrayOf($list['invitations'])[0])['status']);
        self::assertSame('marie', self::arrayOf(self::arrayOf($list['invitations'])[0])['usedBy']);
    }

    public function testARevokedInvitationNoLongerWorks(): void
    {
        $this->seedAndLogin();
        [$token, $id] = $this->invite();

        $this->call('DELETE', '/web/invitations/'.$id);
        self::assertSame(204, $this->statusCode());
        $this->call('DELETE', '/web/invitations/'.$id);
        self::assertSame(404, $this->statusCode());
        $this->call('POST', '/web/logout');

        $body = $this->call('POST', '/web/register', ['email' => 'marie@example.test', 'username' => 'marie', 'password' => self::PASSWORD, 'invitation' => $token]);

        self::assertSame(403, $this->statusCode());
        self::assertSame('invalid_invitation', $body['error']);
    }

    public function testInvitationsAreAudited(): void
    {
        $admin = $this->seedAndLogin();
        [, $id] = $this->invite();
        $this->call('DELETE', '/web/invitations/'.$id);

        $actions = array_map(static fn ($event): string => $event->getAction()->value, $this->auditEvents($admin, 'invitation'));

        self::assertSame(['invitation.revoked', 'invitation.created'], $actions);
    }

    /** @var array<string, string|null> valeurs d'origine, rendues à la fin du test */
    private array $originalEnv = [];

    /**
     * @param array<string, string> $env
     */
    private function withMail(array $env): void
    {
        foreach ($env as $name => $value) {
            $original = $_SERVER[$name] ?? null;
            $this->originalEnv += [$name => \is_string($original) ? $original : null];
            $_SERVER[$name] = $_ENV[$name] = $value;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $name => $value) {
            if (null === $value) {
                unset($_SERVER[$name], $_ENV[$name]);
            } else {
                $_SERVER[$name] = $_ENV[$name] = $value;
            }
        }
        $this->originalEnv = [];
        parent::tearDown();
    }

    private function configureMail(string $dsn = 'null://null'): void
    {
        $this->withMail(['TERRILDB_MAIL_FROM' => 'terrildb@example.test', 'TERRILDB_PUBLIC_URL' => 'https://db.example.test', 'MAILER_DSN' => $dsn]);
    }

    public function testTellsWhetherInvitationsCanBeMailed(): void
    {
        $this->seedAndLogin();
        self::assertFalse($this->call('GET', '/web/invitations')['emailEnabled']);

        $this->configureMail();

        self::assertTrue($this->call('GET', '/web/invitations')['emailEnabled']);
    }

    public function testRefusesToMailWhenNothingIsConfiguredAndCreatesNothing(): void
    {
        $this->seedAndLogin();

        $body = $this->call('POST', '/web/invitations', ['note' => 'x', 'email' => 'marie@example.test']);

        self::assertSame(409, $this->statusCode());
        self::assertSame('mail_disabled', $body['error']);
        self::assertSame([], $this->call('GET', '/web/invitations')['invitations']);
    }

    public function testMailsTheInvitationAndStillShowsTheLink(): void
    {
        $admin = $this->seedAndLogin();
        $this->configureMail();

        $body = $this->call('POST', '/web/invitations', ['note' => 'Pour Marie', 'email' => 'marie@example.test']);

        self::assertSame(201, $this->statusCode());
        self::assertTrue($body['emailSent']);
        self::assertIsString($body['token']);
        $created = $this->auditEvents($admin, 'invitation')[0];
        self::assertSame(['lifetimeDays' => 7, 'emailed' => true], $created->getDetails());
        self::assertStringNotContainsString('marie@', json_encode($created->getDetails(), \JSON_THROW_ON_ERROR));
    }

    public function testAFailingTransportKeepsTheInvitation(): void
    {
        $admin = $this->seedAndLogin();
        $this->configureMail('smtp://127.0.0.1:1');

        $body = $this->call('POST', '/web/invitations', ['note' => 'Pour Marie', 'email' => 'marie@example.test']);

        self::assertSame(201, $this->statusCode());
        self::assertFalse($body['emailSent']);
        self::assertIsString($body['token']);
        self::assertCount(1, self::arrayOf($this->call('GET', '/web/invitations')['invitations']));
        self::assertFalse($this->auditEvents($admin, 'invitation')[0]->getDetails()['emailed'] ?? null);
    }

    public function testAnInvalidAddressCreatesNothing(): void
    {
        $this->seedAndLogin();
        $this->configureMail();

        $this->call('POST', '/web/invitations', ['email' => 'not an address']);

        self::assertSame(422, $this->statusCode());
        self::assertSame([], $this->call('GET', '/web/invitations')['invitations']);
    }

    public function testWithoutAnAddressNothingIsSent(): void
    {
        $this->seedAndLogin();
        $this->configureMail();

        $body = $this->call('POST', '/web/invitations', ['note' => 'x']);

        self::assertSame(201, $this->statusCode());
        self::assertFalse($body['emailSent']);
    }
}

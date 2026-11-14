<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Audit\AuditAction;
use App\Tests\Integration\Driver\TargetDatabase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Connexions à rebond SSH de bout en bout (#13) : formulaire, essai, ouverture par le tunnel, règles d'adresses, empreinte de
 * la clé d'hôte. Demande le conteneur `tunnel`, le bastion et la base cachée de test (profil « targets ») ; sauté sinon.
 */
#[Group('integration')]
final class JumpConnectionsWebTest extends WebApiTestCase
{
    private string $key = '';
    private string $fingerprint = '';

    protected function setUp(): void
    {
        parent::setUp();
        $key = @file_get_contents('/run/bastion-keys/client_key');
        $fingerprint = @file_get_contents('/run/bastion-keys/host_fingerprint');
        if (false === $key || false === $fingerprint || !file_exists('/run/tunnels/control.sock')) {
            self::markTestSkipped('Le conteneur tunnel et le bastion de test ne tournent pas (profil targets).');
        }
        $this->key = $key;
        $this->fingerprint = trim($fingerprint);
    }

    /**
     * @param array<string, mixed> $transport
     *
     * @return array<string, mixed>
     */
    private function body(array $transport = [], string $host = 'target-behind-bastion', string $name = 'Hidden'): array
    {
        $postgres = TargetDatabase::parameters('TARGET_POSTGRES_URL');

        return [
            'name' => $name, 'engine' => 'postgresql', 'host' => $host, 'port' => 5432, 'database' => 'shop',
            'username' => $postgres->username, 'password' => $postgres->password(),
            'transport' => $transport + ['type' => 'ssh', 'host' => 'target-bastion', 'port' => 22, 'username' => 'jump', 'privateKey' => $this->key, 'hostKeyFingerprint' => $this->fingerprint],
        ];
    }

    private function loginAsMember(): void
    {
        $this->seedAndLogin('alice');
        $this->call('POST', '/web/logout');
        $this->seedUser('bob');
        $this->login('bob');
    }

    private function createdId(mixed $response): string
    {
        $id = self::arrayOf(self::arrayOf($response)['connection'])['id'];
        self::assertIsString($id);

        return $id;
    }

    public function testAConnectionThroughTheBastionIsSavedAndOpenedByTheTunnel(): void
    {
        $this->seedAndLogin();

        $created = $this->call('POST', '/web/connections', $this->body());
        self::assertSame(201, $this->statusCode());
        $id = $this->createdId($created);

        $tables = $this->call('GET', '/web/connections/'.$id.'/tables');
        self::assertSame(200, $this->statusCode());
        self::assertSame([], $tables['tables']);
        $this->call('POST', '/web/connections/'.$id.'/test');
        self::assertSame(200, $this->statusCode());
    }

    public function testTheFormIsFilledBackWithoutTheKey(): void
    {
        $this->seedAndLogin();
        $id = $this->createdId($this->call('POST', '/web/connections', $this->body()));

        $shown = $this->call('GET', '/web/connections/'.$id);

        self::assertSame(200, $this->statusCode());
        self::assertSame(
            ['type' => 'ssh', 'host' => 'target-bastion', 'port' => 22, 'username' => 'jump', 'hasPrivateKey' => true, 'hostKeyFingerprint' => $this->fingerprint],
            self::arrayOf($shown['parameters'])['transport'],
        );
        self::assertStringNotContainsString('PRIVATE KEY', (string) $this->client->getResponse()->getContent());
        self::assertArrayNotHasKey('transport', self::arrayOf(self::arrayOf($this->call('GET', '/web/connections')['connections'])[0]));
    }

    public function testNothingAboutTheBastionIsReadableInTheInternalDatabase(): void
    {
        $this->seedAndLogin();
        $this->call('POST', '/web/connections', $this->body());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $stored = $entityManager->getConnection()->fetchOne('SELECT encrypted_parameters FROM database_connection');
        self::assertIsString($stored);

        foreach (['target-bastion', 'PRIVATE KEY', $this->fingerprint, 'target-behind-bastion'] as $secret) {
            self::assertStringNotContainsString($secret, $stored);
        }
    }

    public function testAConnectionCanBeTriedBeforeBeingSavedThroughTheSameTunnel(): void
    {
        $this->seedAndLogin();

        $this->call('POST', '/web/connections/test', $this->body());

        self::assertSame(200, $this->statusCode());
    }

    public function testTheHiddenTargetIsNotReachedDirectlyEvenWithoutTheJump(): void
    {
        $this->seedAndLogin();
        $direct = $this->body();
        unset($direct['transport']);

        $this->call('POST', '/web/connections/test', $direct);

        self::assertSame(502, $this->statusCode());
    }

    public function testABastionOutsideTheAdministratorsListIsRefusedFromTheSave(): void
    {
        $this->seedAndLogin();

        $body = $this->call('POST', '/web/connections', $this->body(['host' => 'other.example']));

        self::assertSame(403, $this->statusCode());
        self::assertSame('forbidden_bastion', $body['error']);
        self::assertSame([], $this->call('GET', '/web/connections')['connections']);
        $this->call('POST', '/web/connections/test', $this->body(['host' => 'other.example']));
        self::assertSame(403, $this->statusCode());
    }

    public function testAMemberCannotAimTheServerItselfBehindTheBastion(): void
    {
        $this->loginAsMember();

        foreach (['127.0.0.1', 'localhost', 'db'] as $target) {
            $body = $this->call('POST', '/web/connections', $this->body(host: $target));
            self::assertSame(403, $this->statusCode(), $target);
            self::assertSame('forbidden_target', $body['error'], $target);
            $this->call('POST', '/web/connections/test', $this->body(host: $target));
            self::assertSame(403, $this->statusCode(), $target);
        }
    }

    public function testAMemberCannotUseTheServerItselfAsBastionEither(): void
    {
        $this->loginAsMember();

        $body = $this->call('POST', '/web/connections', $this->body(['host' => '127.0.0.1']));

        self::assertSame(403, $this->statusCode());
        self::assertSame('forbidden_target', $body['error']);
    }

    public function testAnUnknownHostKeyIsRefusedWithoutTrustOnFirstUse(): void
    {
        $user = $this->seedAndLogin();
        $body = $this->body();
        $body['transport'] = array_diff_key(self::arrayOf($body['transport']), ['hostKeyFingerprint' => 1]);
        $id = $this->createdId($this->call('POST', '/web/connections', $body));

        $failure = $this->call('GET', '/web/connections/'.$id.'/tables');

        self::assertSame(502, $this->statusCode());
        self::assertSame('tunnel_failed', $failure['error']);
        self::assertSame('host_key_unconfirmed', $failure['reason']);
        $events = array_filter($this->auditEvents($user), static fn ($event): bool => AuditAction::TunnelFailed === $event->getAction());
        self::assertCount(1, $events);
        self::assertSame('host_key_unconfirmed', array_values($events)[0]->getDetails()['reason'] ?? null);
    }

    public function testAChangedHostKeyIsAPlainRefusalThatLeaksNothing(): void
    {
        $this->seedAndLogin();
        $id = $this->createdId($this->call('POST', '/web/connections', $this->body(['hostKeyFingerprint' => 'SHA256:'.str_repeat('A', 43)])));

        $failure = $this->call('GET', '/web/connections/'.$id.'/tables');

        self::assertSame(502, $this->statusCode());
        self::assertSame('host_key_changed', $failure['reason']);
        $text = (string) $this->client->getResponse()->getContent();
        foreach (['target-bastion', 'target-behind-bastion', $this->fingerprint, 'PRIVATE KEY'] as $leak) {
            self::assertStringNotContainsString($leak, $text);
        }
    }

    public function testAWrongKeyFailsGenericallyAndTheAuditKeepsOnlyTheReason(): void
    {
        $user = $this->seedAndLogin();
        $id = $this->createdId($this->call('POST', '/web/connections', $this->body(['privateKey' => str_replace('AAAA', 'AAAB', $this->key)])));

        $failure = $this->call('GET', '/web/connections/'.$id.'/tables');

        self::assertSame(502, $this->statusCode());
        self::assertSame(['error' => 'tunnel_failed', 'reason' => 'unavailable'], array_intersect_key($failure, ['error' => 1, 'reason' => 1]));
        $text = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('target-bastion', $text);
        $audited = json_encode(array_map(static fn ($event): array => $event->getDetails(), $this->auditEvents($user)), \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('PRIVATE KEY', $audited);
        self::assertStringNotContainsString($this->fingerprint, $audited);
    }

    public function testTheUserReadsTheHostKeyToConfirmItThenSavesIt(): void
    {
        $this->seedAndLogin();

        $read = $this->call('POST', '/web/connections/host-key', ['host' => 'target-bastion', 'port' => 22]);

        self::assertSame(200, $this->statusCode());
        self::assertSame(['fingerprint' => $this->fingerprint, 'keyType' => 'ed25519'], $read);
        $id = $this->createdId($this->call('POST', '/web/connections', $this->body(['hostKeyFingerprint' => $read['fingerprint']])));
        $this->call('GET', '/web/connections/'.$id.'/tables');
        self::assertSame(200, $this->statusCode());
    }

    public function testReadingTheHostKeyOfAnUnlistedBastionOrOfTheServerItselfIsRefused(): void
    {
        $this->loginAsMember();

        $body = $this->call('POST', '/web/connections/host-key', ['host' => 'other.example', 'port' => 22]);
        self::assertSame(403, $this->statusCode());
        self::assertSame('forbidden_bastion', $body['error']);

        $body = $this->call('POST', '/web/connections/host-key', ['host' => '127.0.0.1', 'port' => 22]);
        self::assertSame(403, $this->statusCode());
        self::assertSame('forbidden_target', $body['error']);

        $this->call('POST', '/web/connections/host-key', ['host' => 'target-bastion', 'port' => 'x']);
        self::assertSame(400, $this->statusCode());
    }

    public function testAnUpdateWithoutKeyOrFingerprintKeepsThemAndNullRemovesTheJump(): void
    {
        $this->seedAndLogin();
        $id = $this->createdId($this->call('POST', '/web/connections', $this->body()));
        $update = $this->body(name: 'Renamed');
        $update['transport'] = array_diff_key(self::arrayOf($update['transport']), ['privateKey' => 1, 'hostKeyFingerprint' => 1]);

        $this->call('PUT', '/web/connections/'.$id, $update);
        self::assertSame(200, $this->statusCode());
        $this->call('GET', '/web/connections/'.$id.'/tables');
        self::assertSame(200, $this->statusCode());

        $absent = $this->body(name: 'Renamed');
        unset($absent['transport']);
        $this->call('PUT', '/web/connections/'.$id, $absent);
        $shown = $this->call('GET', '/web/connections/'.$id);
        self::assertSame($this->statusCode(), 200);
        self::assertArrayHasKey('transport', self::arrayOf($shown['parameters']), 'une modification sans transport garde le rebond');

        $none = $this->body(name: 'Renamed');
        $none['transport'] = null;
        $this->call('PUT', '/web/connections/'.$id, $none);
        $shown = $this->call('GET', '/web/connections/'.$id);
        self::assertArrayNotHasKey('transport', self::arrayOf($shown['parameters']));
    }

    public function testMovingTheJumpToAnUnlistedBastionIsRefusedAndChangesNothing(): void
    {
        $this->seedAndLogin();
        $id = $this->createdId($this->call('POST', '/web/connections', $this->body()));
        $update = $this->body(['port' => 2222]);
        $update['transport'] = array_diff_key(self::arrayOf($update['transport']), ['hostKeyFingerprint' => 1]);

        $refused = $this->call('PUT', '/web/connections/'.$id, $update);

        self::assertSame(403, $this->statusCode());
        self::assertSame('forbidden_bastion', $refused['error']);
        $transport = self::arrayOf(self::arrayOf($this->call('GET', '/web/connections/'.$id)['parameters'])['transport']);
        self::assertSame(22, $transport['port']);
        self::assertSame($this->fingerprint, $transport['hostKeyFingerprint']);
    }

    public function testAnInvalidJumpIsRefusedAsInvalidInput(): void
    {
        $this->seedAndLogin();

        foreach ([['type' => 'socks5'], ['privateKey' => 'not a key'], ['hostKeyFingerprint' => 'MD5:aa'], ['port' => 0]] as $bad) {
            $this->call('POST', '/web/connections', $this->body($bad));
            self::assertSame(422, $this->statusCode(), json_encode($bad, \JSON_THROW_ON_ERROR));
        }
    }

    public function testAClickHouseConnectionCannotGoThroughAJumpYet(): void
    {
        $this->seedAndLogin();
        $body = ['engine' => 'clickhouse', 'port' => 8123] + $this->body();
        $id = $this->createdId($this->call('POST', '/web/connections', $body));

        $failure = $this->call('GET', '/web/connections/'.$id.'/tables');

        self::assertSame(501, $this->statusCode());
        self::assertSame('unsupported_operation', $failure['error']);
    }
}

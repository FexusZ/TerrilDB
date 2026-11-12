<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Core\Connection\Engine;
use App\Driver\DbalConnector;
use App\Entity\User;
use App\Tests\Integration\Driver\TargetDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Écrire par l'API en DEUX temps : `prepare` (rien ne part, un jeton de confirmation à usage unique est rendu), puis
 * `execute` avec ce jeton. Sans jeton valide, aucune écriture ne part, sauf pour un jeton d'API « une seule étape ».
 */
#[Group('integration')]
final class WriteApprovalApiTest extends WebApiTestCase
{
    private ?Connection $target = null;
    private string $table = '';
    private string $connectionId = '';
    private string $token = '';
    private ?User $owner = null;

    protected function tearDown(): void
    {
        if ('' !== $this->table) {
            $this->target()->executeStatement('DROP TABLE IF EXISTS '.$this->table);
        }
        $this->target?->close();
        parent::tearDown();
    }

    private function target(): Connection
    {
        return $this->target ??= (new DbalConnector())->connect(Engine::PostgreSql, TargetDatabase::parameters('TARGET_POSTGRES_URL'));
    }

    /**
     * @param array<string, bool> $rights
     */
    private function setUpToken(array $rights = ['writeData' => true]): void
    {
        $this->table = 'tdb_wa_'.bin2hex(random_bytes(4));
        $this->target()->executeStatement(\sprintf("CREATE TABLE %s (id integer PRIMARY KEY, v varchar(20)); INSERT INTO %1\$s VALUES (1, 'a')", $this->table));
        $this->owner = $this->seedAndLogin();
        $parameters = TargetDatabase::parameters('TARGET_POSTGRES_URL');
        $created = $this->call('POST', '/web/connections', ['name' => 'Target', 'engine' => 'postgresql', 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()]);
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);
        $this->connectionId = $id;
        $token = $this->call('POST', '/web/tokens', ['name' => 'Assistant', 'connectionIds' => [$id]] + $rights);
        self::assertIsString($token['token']);
        $this->token = $token['token'];
        $this->client->getCookieJar()->clear();
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function api(string $path, array $body): array
    {
        return $this->call('POST', '/api/v1/connections/'.$this->connectionId.'/'.$path, $body, csrf: false, extraServer: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);
    }

    private function value(): string
    {
        $value = $this->target()->fetchOne(\sprintf('SELECT v FROM %s WHERE id = 1', $this->table));

        return \is_string($value) ? $value : '';
    }

    private function update(string $to = 'b'): string
    {
        return \sprintf("UPDATE %s SET v = '%s' WHERE id = 1", $this->table, $to);
    }

    private function ticket(string $sql): string
    {
        $prepared = $this->api('prepare', ['sql' => $sql]);
        self::assertSame(200, $this->statusCode());
        self::assertIsString($prepared['confirmationToken']);

        return $prepared['confirmationToken'];
    }

    public function testWithoutAPreparationNothingIsWritten(): void
    {
        $this->setUpToken();

        $body = $this->api('execute', ['sql' => $this->update()]);

        self::assertSame(409, $this->statusCode());
        self::assertSame(['error' => 'preparation_required'], $body);
        self::assertSame('a', $this->value());
    }

    public function testPrepareChangesNothingAndReturnsAPreviewWithATicket(): void
    {
        $this->setUpToken();

        $body = $this->api('prepare', ['sql' => $this->update()]);

        self::assertSame(200, $this->statusCode());
        self::assertSame(['allowed', 'write', 'normal'], [$body['decision'], $body['kind'], $body['danger']]);
        self::assertNull($body['reason']);
        self::assertIsString($body['confirmationToken']);
        self::assertStringStartsWith('cft_', $body['confirmationToken']);
        self::assertSame(300, $body['expiresInSeconds']);
        self::assertSame('a', $this->value());
    }

    public function testTheTicketExecutesTheExactStatementOnce(): void
    {
        $this->setUpToken();
        $ticket = $this->ticket($this->update());

        $done = $this->api('execute', ['sql' => $this->update(), 'confirmationToken' => $ticket]);

        self::assertSame(200, $this->statusCode());
        self::assertSame(1, $done['affectedRows']);
        self::assertSame('b', $this->value());

        $this->target()->executeStatement(\sprintf("UPDATE %s SET v = 'a'", $this->table));
        $replay = $this->api('execute', ['sql' => $this->update(), 'confirmationToken' => $ticket]);
        self::assertSame(403, $this->statusCode());
        self::assertSame(['error' => 'invalid_confirmation'], $replay);
        self::assertSame('a', $this->value());
    }

    public function testAStatementChangedByOneByteIsRefusedAndTheTicketIsBurned(): void
    {
        $this->setUpToken();
        $ticket = $this->ticket($this->update());

        $body = $this->api('execute', ['sql' => $this->update().' ', 'confirmationToken' => $ticket]);
        self::assertSame(403, $this->statusCode());
        self::assertSame('invalid_confirmation', $body['error']);
        self::assertSame('a', $this->value());

        $this->api('execute', ['sql' => $this->update(), 'confirmationToken' => $ticket]);
        self::assertSame(403, $this->statusCode());
        self::assertSame('a', $this->value());
    }

    public function testAnExpiredTicketIsRefused(): void
    {
        $this->setUpToken();
        $ticket = $this->ticket($this->update());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->getConnection()->executeStatement("UPDATE confirmation_ticket SET expires_at = now() - interval '1 minute'");

        $this->api('execute', ['sql' => $this->update(), 'confirmationToken' => $ticket]);

        self::assertSame(403, $this->statusCode());
        self::assertSame('a', $this->value());
    }

    public function testAnUnknownTicketIsRefused(): void
    {
        $this->setUpToken();

        $this->api('execute', ['sql' => $this->update(), 'confirmationToken' => 'cft_made_up']);

        self::assertSame(403, $this->statusCode());
        self::assertSame('a', $this->value());
    }

    public function testADestructiveStatementNeedsTheTicketAndTheConfirmedFlag(): void
    {
        $this->setUpToken();
        $sql = 'DELETE FROM '.$this->table;
        $prepared = $this->api('prepare', ['sql' => $sql]);
        self::assertSame(['needs_confirmation', 'destructive', 'destructive_without_where'], [$prepared['decision'], $prepared['danger'], $prepared['reason']]);
        $ticket = $prepared['confirmationToken'];
        self::assertIsString($ticket);

        $asked = $this->api('execute', ['sql' => $sql, 'confirmationToken' => $ticket]);
        self::assertSame(409, $this->statusCode());
        self::assertSame('confirmation_required', $asked['error']);
        self::assertSame('a', $this->value(), 'rien n\'est parti, et le jeton n\'a pas été brûlé');

        $done = $this->api('execute', ['sql' => $sql, 'confirmationToken' => $ticket, 'confirmed' => true]);
        self::assertSame(200, $this->statusCode());
        self::assertSame(1, $done['affectedRows']);
    }

    public function testAStatementTheTokenMayNotRunGetsNoTicket(): void
    {
        $this->setUpToken(['writeData' => true]);

        $body = $this->api('prepare', ['sql' => 'CREATE TABLE sneaky (a int)']);

        self::assertSame(403, $this->statusCode());
        self::assertSame(['error' => 'query_refused', 'reason' => 'write_not_permitted'], $body);
    }

    public function testAReadOnlyTokenCannotPrepare(): void
    {
        $this->setUpToken([]);

        $body = $this->api('prepare', ['sql' => $this->update()]);

        self::assertSame(403, $this->statusCode());
        self::assertSame(['error' => 'permission_denied'], $body);
    }

    public function testASingleStepTokenKeepsTheDirectBehaviourButStillChecksAGivenTicket(): void
    {
        $this->setUpToken(['writeData' => true, 'singleStep' => true]);

        $this->api('execute', ['sql' => $this->update()]);
        self::assertSame(200, $this->statusCode());
        self::assertSame('b', $this->value());

        $this->api('execute', ['sql' => $this->update('c'), 'confirmationToken' => 'cft_made_up']);
        self::assertSame(403, $this->statusCode());
        self::assertSame('b', $this->value());
    }

    public function testPreparationsAndRejectionsAreAudited(): void
    {
        $this->setUpToken();
        self::assertNotNull($this->owner);
        $ticket = $this->ticket($this->update());
        $this->api('execute', ['sql' => $this->update('z'), 'confirmationToken' => $ticket]);

        $events = $this->auditEvents($this->owner, 'query');
        $actions = array_map(static fn ($e): string => $e->getAction()->value.':'.$e->getOutcome()->value, $events);

        self::assertContains('query.prepared:success', $actions);
        self::assertContains('query.refused:refused', $actions);
        foreach ($events as $event) {
            if ('query.refused' === $event->getAction()->value) {
                self::assertSame('invalid_confirmation', $event->getDetails()['reason']);
                self::assertSame($this->update('z'), $event->getDetails()['sql']);
            }
            if ('query.prepared' === $event->getAction()->value) {
                self::assertSame($this->update(), $event->getDetails()['sql']);
                self::assertSame('allowed', $event->getDetails()['decision']);
                self::assertArrayNotHasKey('confirmationToken', $event->getDetails());
            }
        }
    }
}

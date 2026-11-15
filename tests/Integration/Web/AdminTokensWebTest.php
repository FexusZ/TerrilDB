<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

#[Group('integration')]
final class AdminTokensWebTest extends WebApiTestCase
{
    private const NIL = '00000000-0000-7000-8000-000000000000';

    private function makeAdmin(User $user): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->getConnection()->executeStatement("UPDATE app_user SET role = 'admin' WHERE id = ?", [$user->getId()->toRfc4122()]);
    }

    /**
     * Crée un jeton au nom du compte connecté et rend son texte.
     *
     * @param array<string, mixed> $extra
     */
    private function issueToken(string $name, array $extra = []): string
    {
        $created = $this->call('POST', '/web/tokens', ['name' => $name, 'allConnections' => true, 'lifetimeDays' => 30, ...$extra]);
        self::assertSame(201, $this->statusCode());

        return self::stringOf($created['token']);
    }

    /**
     * @return list<array<mixed>>
     */
    private function adminTokens(): array
    {
        return array_map(self::arrayOf(...), self::listOf($this->call('GET', '/web/admin/tokens')['tokens']));
    }

    private function setDisabled(Uuid $id): void
    {
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $user = $users->find($id);
        self::assertNotNull($user);
        $user->disable(new \DateTimeImmutable());
        $users->save($user);
    }

    public function testRoutesRequireASession(): void
    {
        foreach ([['GET', '/web/admin/tokens'], ['DELETE', '/web/admin/tokens/'.self::NIL], ['GET', '/web/admin/journal']] as [$method, $uri]) {
            $this->call($method, $uri);
            self::assertSame(401, $this->statusCode(), $uri);
        }
    }

    public function testAMemberIsForbiddenEverywhereAndNeverSeesAnotherAccountsToken(): void
    {
        $this->seedAndLogin('alice');
        $this->issueToken('Alice assistant');
        $this->call('POST', '/web/logout');
        $this->seedUser('bob');
        $this->login('bob');

        foreach ([['GET', '/web/admin/tokens'], ['DELETE', '/web/admin/tokens/'.self::NIL], ['GET', '/web/admin/journal']] as [$method, $uri]) {
            $body = $this->call($method, $uri);
            self::assertSame(403, $this->statusCode(), $uri);
            self::assertSame('forbidden', $body['error']);
        }
    }

    public function testTheAdministratorSeesTheTokensOfEveryAccountWithoutAnySecret(): void
    {
        $alice = $this->seedUser('alice');
        $bob = $this->seedUser('bob');
        $this->login('bob');
        $this->issueToken('Bob lecture', ['diagnostic' => true]);
        $this->call('POST', '/web/logout');
        $this->login('alice');
        $this->issueToken('Alice ecriture', ['writeData' => true]);

        $body = $this->call('GET', '/web/admin/tokens');

        self::assertSame(200, $this->statusCode());
        $tokens = $this->adminTokens();
        self::assertCount(2, $tokens);
        $byName = [];
        foreach ($tokens as $token) {
            $byName[self::stringOf($token['name'])] = $token;
        }
        $bobToken = $byName['Bob lecture'];
        self::assertSame($bob->getId()->toRfc4122(), self::arrayOf($bobToken['owner'])['id']);
        self::assertSame('bob', self::arrayOf($bobToken['owner'])['username']);
        self::assertTrue(self::arrayOf($bobToken['permissions'])['diagnostic']);
        self::assertFalse(self::arrayOf($bobToken['permissions'])['writeData']);
        self::assertTrue(self::arrayOf($byName['Alice ecriture']['permissions'])['writeData']);
        self::assertSame('active', $bobToken['status']);
        self::assertTrue($bobToken['allConnections']);
        self::assertSame(0, $bobToken['connectionCount']);
        self::assertNull($bobToken['lastUsedAt']);
        self::assertNotNull($bobToken['createdAt']);
        self::assertNotNull($bobToken['expiresAt']);
        self::assertFalse(self::arrayOf($bobToken['owner'])['disabled']);
        self::assertSame($alice->getId()->toRfc4122(), self::arrayOf($byName['Alice ecriture']['owner'])['id']);

        // Aucun champ secret, aucune clé enveloppée, aucune empreinte : on scanne toute la réponse.
        $json = json_encode($body, \JSON_THROW_ON_ERROR);
        foreach (array_keys($this->flattenKeys($body)) as $key) {
            self::assertDoesNotMatchRegularExpression('/secret|envelope|wrapped|hash|verifier|password|email|key/i', (string) $key);
        }
        self::assertStringNotContainsString('tdb_', $json);
        self::assertStringNotContainsString('v1:', $json);
        self::assertStringNotContainsString('example.test', $json);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, true>
     */
    private function flattenKeys(array $data): array
    {
        $keys = [];
        foreach ($data as $key => $value) {
            $keys[(string) $key] = true;
            if (\is_array($value)) {
                $keys += $this->flattenKeys($value);
            }
        }

        return $keys;
    }

    public function testExpiredAndDisabledOwnerAreMarkedButStillListed(): void
    {
        $alice = $this->seedUser('alice');
        $bob = $this->seedUser('bob');
        $this->login('bob');
        $this->issueToken('Bob jeton');
        $this->issueToken('Bob ancien');
        $this->call('POST', '/web/logout');
        $this->login('alice');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->getConnection()->executeStatement("UPDATE api_token SET expires_at = '2000-01-01 00:00:00' WHERE name = 'Bob ancien'");
        $this->setDisabled($bob->getId());

        $tokens = $this->adminTokens();

        self::assertSame('expired', $this->tokenNamed($tokens, 'Bob ancien')['status']);
        self::assertSame('active', $this->tokenNamed($tokens, 'Bob jeton')['status']);
        foreach ($tokens as $token) {
            self::assertTrue(self::arrayOf($token['owner'])['disabled']);
        }
    }

    /**
     * @param list<array<mixed>> $tokens
     *
     * @return array<mixed>
     */
    private function tokenNamed(array $tokens, string $name): array
    {
        foreach ($tokens as $token) {
            if ($token['name'] === $name) {
                return $token;
            }
        }
        self::fail('No token named '.$name);
    }

    public function testRevokingATokenMakesTheApiAnswer401AndIsAuditedWithIdsOnly(): void
    {
        $alice = $this->seedUser('alice');
        $bob = $this->seedUser('bob');
        $this->login('bob');
        $text = $this->issueToken('Bob secret name');
        $this->call('POST', '/web/logout');
        $this->login('alice');
        $id = self::stringOf($this->adminTokens()[0]['id']);
        $bearer = ['HTTP_AUTHORIZATION' => 'Bearer '.$text];
        $this->call('GET', '/api/v1/token', csrf: false, extraServer: $bearer);
        self::assertSame(200, $this->statusCode());
        $this->login('alice');

        $this->call('DELETE', '/web/admin/tokens/'.$id);

        self::assertSame(204, $this->statusCode());
        $this->call('GET', '/api/v1/token', csrf: false, extraServer: $bearer);
        self::assertSame(401, $this->statusCode());
        self::assertSame([], $this->adminTokens());
        $events = $this->auditEvents($alice, 'admin');
        self::assertCount(1, $events);
        self::assertSame('admin.token_revoked', $events[0]->getAction()->value);
        self::assertSame($id, $events[0]->getSubjectId()?->toRfc4122());
        self::assertSame(['owner' => $bob->getId()->toRfc4122()], $events[0]->getDetails());
        self::assertSame([], $this->auditEvents($bob, 'admin'));
    }

    public function testAnUnknownTokenIsNotFoundAndNothingIsJournaled(): void
    {
        $alice = $this->seedAndLogin('alice');

        $body = $this->call('DELETE', '/web/admin/tokens/'.Uuid::v7()->toRfc4122());

        self::assertSame(404, $this->statusCode());
        self::assertSame('not_found', $body['error']);
        self::assertSame([], $this->auditEvents($alice, 'admin'));
    }

    public function testRevocationNeedsTheRequestHeader(): void
    {
        $this->seedAndLogin('alice');
        $this->issueToken('Jeton');
        $id = self::stringOf($this->adminTokens()[0]['id']);

        $this->call('DELETE', '/web/admin/tokens/'.$id, csrf: false);

        self::assertSame(403, $this->statusCode());
        self::assertCount(1, $this->adminTokens());
    }

    public function testTheJournalListsOnlyAdministrationActionsOfAllAdministratorsWithACursor(): void
    {
        $alice = $this->seedUser('alice');
        $bob = $this->seedUser('bob');
        $carol = $this->seedUser('carol');
        $this->makeAdmin($bob);
        $this->login('carol');
        $this->issueToken('Carol jeton');
        $this->call('POST', '/web/logout');
        $this->login('alice');
        $tokenId = self::stringOf($this->adminTokens()[0]['id']);
        $this->call('POST', '/web/accounts/'.$carol->getId()->toRfc4122().'/disable');
        $this->call('POST', '/web/accounts/'.$carol->getId()->toRfc4122().'/enable');
        $this->call('DELETE', '/web/admin/tokens/'.$tokenId);
        $this->call('POST', '/web/logout');
        $this->login('bob');
        $this->call('POST', '/web/accounts/'.$carol->getId()->toRfc4122().'/disable');

        $page = $this->call('GET', '/web/admin/journal?limit=3');

        self::assertSame(200, $this->statusCode());
        $events = array_map(self::arrayOf(...), self::listOf($page['events']));
        self::assertSame(['account.disabled', 'admin.token_revoked', 'account.enabled'], array_column($events, 'action'));
        self::assertSame('bob', self::arrayOf($events[0]['actor'])['username']);
        self::assertSame($carol->getId()->toRfc4122(), $events[0]['subjectId']);
        self::assertSame('alice', self::arrayOf($events[1]['actor'])['username']);
        self::assertIsString($page['next']);

        $rest = $this->call('GET', '/web/admin/journal?limit=3&before='.$page['next']);
        $more = array_map(self::arrayOf(...), self::listOf($rest['events']));
        self::assertSame(['account.disabled'], array_column($more, 'action'));
        self::assertNull($rest['next']);
        $all = json_encode([$page, $rest], \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('login.success', $all);
        self::assertStringNotContainsString('token.created', $all);
        self::assertStringNotContainsString('example.test', $all);
        self::assertSame($alice->getId()->toRfc4122(), self::arrayOf($events[1]['actor'])['id']);
    }

    public function testTheJournalRejectsABadLimitOrCursor(): void
    {
        $this->seedAndLogin('alice');

        $this->call('GET', '/web/admin/journal?limit=0');
        self::assertSame(400, $this->statusCode());
        $this->call('GET', '/web/admin/journal?before=nope');
        self::assertSame(400, $this->statusCode());
    }
}

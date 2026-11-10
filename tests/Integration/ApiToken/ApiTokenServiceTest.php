<?php

declare(strict_types=1);

namespace App\Tests\Integration\ApiToken;

use App\ApiToken\ApiTokenSecret;
use App\ApiToken\ApiTokenService;
use App\ApiToken\Exception\ApiTokenNotFoundException;
use App\ApiToken\Exception\InvalidApiTokenException;
use App\ApiToken\TokenPermissions;
use App\Connection\ConnectionManager;
use App\Connection\Exception\ConnectionNotFoundException;
use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Crypto\DerivedKey;
use App\Crypto\KeyDerivation;
use App\Crypto\SecretCipher;
use App\Crypto\SessionKeyWrapper;
use App\Entity\Exception\InvalidApiTokenDefinitionException;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Repository\DatabaseConnectionRepository;
use App\Repository\UserRepository;
use App\Security\AuthPasswordHasher;
use App\Tests\Integration\Persistence\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[Group('integration')]
final class ApiTokenServiceTest extends DatabaseTestCase
{
    private ApiTokenRepository $tokens;
    private ConnectionManager $connections;
    private MockClock $clock;
    private ApiTokenService $service;
    private User $alice;
    private DerivedKey $key;
    private Uuid $connectionId;

    protected function setUp(): void
    {
        parent::setUp();

        $tokens = self::getContainer()->get(ApiTokenRepository::class);
        self::assertInstanceOf(ApiTokenRepository::class, $tokens);
        $this->tokens = $tokens;
        $repository = self::getContainer()->get(DatabaseConnectionRepository::class);
        self::assertInstanceOf(DatabaseConnectionRepository::class, $repository);
        $this->clock = new MockClock('2026-11-01 10:00:00 UTC');
        $this->connections = new ConnectionManager(new SecretCipher(), $repository, $this->clock);
        $this->service = new ApiTokenService(new SessionKeyWrapper(), $this->tokens, $this->connections, $this->clock);

        $this->alice = $this->user('alice');
        $this->key = new DerivedKey(random_bytes(DerivedKey::LENGTH));
        $this->connectionId = $this->connections->create($this->alice, $this->key, 'Production', Engine::PostgreSql, new ConnectionParameters('db.exemple.test', 5432, 'shop', 'lecteur', 'secret-value'))->getId();
    }

    private function user(string $name): User
    {
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $user = new User($name.'@example.test', $name, (new AuthPasswordHasher(opsLimit: 3, memLimit: 16384))->hash('correct horse battery'), (new KeyDerivation(opsLimit: 1, memLimit: 8192))->newParameters(), new \DateTimeImmutable('2026-10-29T12:00:00Z'));
        $users->save($user);

        return $user;
    }

    private function issue(int $days = 30): string
    {
        return $this->service->issue($this->alice, $this->key, 'Assistant', [$this->connectionId], $days)->token;
    }

    public function testIssuesATokenThatOpensOnlyTheListedConnection(): void
    {
        $issued = $this->service->issue($this->alice, $this->key, 'Assistant', [$this->connectionId], 30);

        self::assertMatchesRegularExpression('/^tdb_[0-9a-f]{32}_[A-Za-z0-9_-]{43}$/D', $issued->token);
        self::assertSame('Assistant', $issued->entity->getName());
        self::assertTrue($issued->entity->allows($this->connectionId));
        self::assertSame('2026-12-01T10:00:00+00:00', $issued->entity->getExpiresAt()?->format(\DATE_ATOM));
    }

    public function testNeitherTheSecretNorTheKeyIsStored(): void
    {
        $issued = $this->service->issue($this->alice, $this->key, 'Assistant', [$this->connectionId], 30);
        $this->entityManager->clear();

        $row = json_encode($this->entityManager->getConnection()->fetchAssociative('SELECT * FROM api_token'), \JSON_THROW_ON_ERROR);
        $secret = substr($issued->token, -43);

        self::assertStringNotContainsString($secret, $row);
        self::assertStringNotContainsString(sodium_bin2base64($this->key->bytes(), \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING), $row);
        self::assertStringNotContainsString(bin2hex($this->key->bytes()), $row);
    }

    public function testAuthenticatingGivesBackTheUserAndTheKey(): void
    {
        $token = $this->issue();

        $access = $this->service->authenticate($token);

        self::assertTrue($access->user->getId()->equals($this->alice->getId()));
        self::assertSame($this->key->bytes(), $access->key->bytes());
        self::assertTrue($access->token->allows($this->connectionId));
    }

    public function testAuthenticatingRecordsTheLastUse(): void
    {
        $token = $this->issue();
        $this->clock->sleep(120);

        $access = $this->service->authenticate($token);
        $this->entityManager->clear();

        self::assertSame('2026-11-01T10:02:00+00:00', $this->tokens->find($access->token->getId())?->getLastUsedAt()?->format(\DATE_ATOM));
    }

    public function testAConcurrentWriteOfTheTokenDoesNotMakeAuthenticationFail(): void
    {
        $token = $this->issue();
        $id = ApiTokenSecret::parse($token)->id;
        $this->clock->sleep(120);
        $this->entityManager->clear();
        // Cette requête a déjà lu le jeton ; une autre l'écrit avant elle (la version du verrou optimiste avance).
        self::assertNotNull($this->tokens->find($id));
        $this->entityManager->getConnection()->executeStatement('UPDATE api_token SET version = version + 1, last_used_at = ? WHERE id = ?', ['2026-11-01 10:02:00', $id->toRfc4122()]);

        $access = $this->service->authenticate($token);

        self::assertTrue($access->token->getId()->equals($id));
        self::assertTrue($this->entityManager->isOpen());
        $this->entityManager->clear();
        self::assertSame('2026-11-01T10:02:00+00:00', $this->tokens->find($id)?->getLastUsedAt()?->format(\DATE_ATOM));
    }

    public function testTheKeyOpensTheConnectionsOfTheOwner(): void
    {
        $access = $this->service->authenticate($this->issue());

        $parameters = $this->connections->reveal($access->user, $access->key, $this->connectionId);

        self::assertSame('secret-value', $parameters->password());
    }

    public function testRefusesAWrongSecretAnUnknownTokenAndGarbage(): void
    {
        $token = $this->issue();
        $wrongSecret = substr($token, 0, -1).('a' === substr($token, -1) ? 'b' : 'a');
        $unknown = 'tdb_'.str_replace('-', '', Uuid::v7()->toRfc4122()).'_'.substr($token, -43);

        foreach ([$wrongSecret, $unknown, 'garbage', ''] as $candidate) {
            try {
                $this->service->authenticate($candidate);
                self::fail('Expected a refusal.');
            } catch (InvalidApiTokenException) {
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testRefusesAnExpiredToken(): void
    {
        $token = $this->issue(1);
        $this->service->authenticate($token);
        $this->clock->sleep(86400);

        $this->expectException(InvalidApiTokenException::class);

        $this->service->authenticate($token);
    }

    public function testIssuesATokenWithItsPermissions(): void
    {
        $issued = $this->service->issue($this->alice, $this->key, 'Assistant', [$this->connectionId], 30, permissions: new TokenPermissions(writeData: true, writeSchema: true, createConnections: true));

        self::assertTrue($issued->entity->getPermissions()->writeData);
        self::assertTrue($issued->entity->getPermissions()->writeSchema);
        self::assertTrue($issued->entity->getPermissions()->createConnections);
    }

    public function testATokenIsReadOnlyUnlessToldOtherwise(): void
    {
        $issued = $this->service->issue($this->alice, $this->key, 'Assistant', [$this->connectionId], 30);

        self::assertFalse($issued->entity->getPermissions()->canWrite());
        self::assertFalse($issued->entity->getPermissions()->createConnections);
    }

    public function testATokenWithoutConnectionIsRefusedUnlessItCoversAllOrCreatesThem(): void
    {
        $this->expectException(InvalidApiTokenDefinitionException::class);
        $this->service->issue($this->alice, $this->key, 'Assistant', [], 30, permissions: new TokenPermissions(writeData: true));
    }

    public function testATokenMayStartWithoutAnyConnectionWhenItCoversAll(): void
    {
        $bob = $this->user('bob');
        $bobKey = new DerivedKey(random_bytes(DerivedKey::LENGTH));

        $issued = $this->service->issue($bob, $bobKey, 'Tout', [], 30, allConnections: true);
        self::assertSame([], $issued->entity->getConnectionIds());

        $later = $this->connections->create($bob, $bobKey, 'Plus tard', Engine::MySql, new ConnectionParameters('h', 3306, 'd', 'u', 'p'))->getId();
        $this->service->refresh($bob, $issued->entity->getId());
        self::assertTrue($issued->entity->allows($later));
    }

    public function testATokenMayStartWithoutAnyConnectionWhenItCanCreateThem(): void
    {
        $issued = $this->service->issue($this->alice, $this->key, 'Assistant', [], 30, permissions: new TokenPermissions(createConnections: true));

        self::assertSame([], $issued->entity->getConnectionIds());
    }

    public function testATokenWithoutLifetimeNeverExpires(): void
    {
        $issued = $this->service->issue($this->alice, $this->key, 'Assistant', [$this->connectionId], null);
        $this->clock->sleep(86400 * 365 * 10);

        self::assertNull($issued->entity->getExpiresAt());
        self::assertSame($this->connectionId->toRfc4122(), $this->service->authenticate($issued->token)->token->getConnectionIds()[0]);
    }

    public function testRefusesATokenWhoseWrappedKeyWasTamperedWith(): void
    {
        $issued = $this->service->issue($this->alice, $this->key, 'Assistant', [$this->connectionId], 30);
        $this->entityManager->getConnection()->executeStatement('UPDATE api_token SET wrapped_key = ? WHERE id = ?', ['v1:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', $issued->entity->getId()->toRfc4122()]);
        $this->entityManager->clear();

        $this->expectException(InvalidApiTokenException::class);

        $this->service->authenticate($issued->token);
    }

    public function testRevokingMakesTheTokenUseless(): void
    {
        $issued = $this->service->issue($this->alice, $this->key, 'Assistant', [$this->connectionId], 30);

        $this->service->revoke($this->alice, $issued->entity->getId());

        $this->expectException(InvalidApiTokenException::class);
        $this->service->authenticate($issued->token);
    }

    public function testOneCannotRevokeSomeoneElsesToken(): void
    {
        $issued = $this->service->issue($this->alice, $this->key, 'Assistant', [$this->connectionId], 30);
        $bob = $this->user('bob');

        try {
            $this->service->revoke($bob, $issued->entity->getId());
            self::fail('Expected the token to be invisible.');
        } catch (ApiTokenNotFoundException) {
        }
        $this->service->authenticate($issued->token);
    }

    public function testRefusesToGrantAnotherUsersConnection(): void
    {
        $bob = $this->user('bob');
        $bobKey = new DerivedKey(random_bytes(DerivedKey::LENGTH));
        $theirs = $this->connections->create($bob, $bobKey, 'Theirs', Engine::MySql, new ConnectionParameters('h', 3306, 'd', 'u', 'p'))->getId();

        $this->expectException(ConnectionNotFoundException::class);

        $this->service->issue($this->alice, $this->key, 'Sneaky', [$theirs], 30);
    }

    public function testRefusesAnUnreasonableLifetimeOrNoConnection(): void
    {
        foreach ([0, -1, 366] as $days) {
            try {
                $this->service->issue($this->alice, $this->key, 'X', [$this->connectionId], $days);
                self::fail('Expected a refusal.');
            } catch (InvalidApiTokenDefinitionException) {
            }
        }
        try {
            $this->service->issue($this->alice, $this->key, 'X', [], 30);
            self::fail('Expected a refusal.');
        } catch (InvalidApiTokenDefinitionException) {
        }
        $this->addToAssertionCount(1);
    }

    public function testListsTheTokensOfTheOwnerNewestFirst(): void
    {
        $this->service->issue($this->alice, $this->key, 'first', [$this->connectionId], 30);
        $this->clock->sleep(10);
        $this->service->issue($this->alice, $this->key, 'second', [$this->connectionId], 30);
        $bob = $this->user('bob');
        $bobKey = new DerivedKey(random_bytes(DerivedKey::LENGTH));
        $bobsConnection = $this->connections->create($bob, $bobKey, 'Theirs', Engine::MySql, new ConnectionParameters('h', 3306, 'd', 'u', 'p'))->getId();
        $this->service->issue($bob, $bobKey, 'bobs', [$bobsConnection], 30);

        self::assertSame(['second', 'first'], array_map(static fn ($t): string => $t->getName(), $this->service->list($this->alice)));
    }

    public function testRevokeAllClearsEveryTokenOfTheOwner(): void
    {
        $a = $this->service->issue($this->alice, $this->key, 'a', [$this->connectionId], 30);
        $b = $this->service->issue($this->alice, $this->key, 'b', [$this->connectionId], 30);

        $this->service->revokeAll($this->alice);
        $this->entityManager->flush();

        foreach ([$a, $b] as $issued) {
            try {
                $this->service->authenticate($issued->token);
                self::fail('Expected a refusal.');
            } catch (InvalidApiTokenException) {
            }
        }
        $this->addToAssertionCount(1);
    }
}

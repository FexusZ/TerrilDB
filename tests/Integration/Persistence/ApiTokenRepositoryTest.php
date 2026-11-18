<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Crypto\KeyDerivation;
use App\Entity\ApiToken;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Repository\UserRepository;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

#[Group('integration')]
final class ApiTokenRepositoryTest extends DatabaseTestCase
{
    private const VERIFIER = 'a3f1c0de9b2e4d5f6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e6f708192a3b';

    private ApiTokenRepository $repository;
    private UserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();

        $repository = self::getContainer()->get(ApiTokenRepository::class);
        self::assertInstanceOf(ApiTokenRepository::class, $repository);
        $this->repository = $repository;
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;
    }

    private function user(string $name): User
    {
        $user = new User($name.'@example.test', $name, '$argon2id$v=19$m=16384,t=3,p=1$c29tZXNhbHQ$aGFzaGhhc2hoYXNoaGFzaA', (new KeyDerivation(opsLimit: 1, memLimit: 8192))->newParameters(), new \DateTimeImmutable('2026-11-01T10:00:00Z'));
        $this->users->save($user);

        return $user;
    }

    private function token(User $owner, string $name = 'CI', string $createdAt = '2026-11-01T10:00:00Z'): ApiToken
    {
        return new ApiToken(Uuid::v7(), $owner, $name, self::VERIFIER, 'v1:AAAAwrapped', [Uuid::v7()->toRfc4122()], new \DateTimeImmutable($createdAt), new \DateTimeImmutable('2026-12-01T10:00:00Z'));
    }

    public function testASavedTokenComesBackWithEveryField(): void
    {
        $alice = $this->user('alice');
        $token = $this->token($alice);
        $token->recordUse(new \DateTimeImmutable('2026-11-02T08:00:00Z'));
        $this->repository->save($token);
        $this->entityManager->clear();

        $found = $this->repository->find($token->getId());

        self::assertNotNull($found);
        self::assertSame('CI', $found->getName());
        self::assertSame('v1:AAAAwrapped', $found->getWrappedKey());
        self::assertSame($token->getConnectionIds(), $found->getConnectionIds());
        self::assertSame('2026-12-01T10:00:00+00:00', $found->getExpiresAt()?->format(\DATE_ATOM));
        self::assertSame('2026-11-02T08:00:00+00:00', $found->getLastUsedAt()?->format(\DATE_ATOM));
        self::assertTrue($found->matches(self::VERIFIER));
    }

    public function testATokenWithoutExpiryComesBackWithoutExpiry(): void
    {
        $token = new ApiToken(Uuid::v7(), $this->user('alice'), 'CI', self::VERIFIER, 'v1:AAAAwrapped', [Uuid::v7()->toRfc4122()], new \DateTimeImmutable('2026-11-01T10:00:00Z'), null);
        $this->repository->save($token);
        $this->entityManager->clear();

        $found = $this->repository->find($token->getId());

        self::assertNotNull($found);
        self::assertNull($found->getExpiresAt());
    }

    public function testTokensAreListedNewestFirstForTheirOwnerOnly(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $this->repository->save($this->token($alice, 'old', '2026-11-01T10:00:00Z'));
        $this->repository->save($this->token($alice, 'new', '2026-11-03T10:00:00Z'));
        $this->repository->save($this->token($bob, 'bobs'));
        $this->entityManager->clear();

        $names = array_map(static fn (ApiToken $t): string => $t->getName(), $this->repository->findAllOwnedBy($alice));

        self::assertSame(['new', 'old'], $names);
    }

    public function testAnotherUsersTokenIsInvisible(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $token = $this->token($alice);
        $this->repository->save($token);
        $this->entityManager->clear();

        self::assertNull($this->repository->findOwned($bob, $token->getId()));
        self::assertNotNull($this->repository->findOwned($alice, $token->getId()));
    }

    public function testRemoveDeletesTheToken(): void
    {
        $alice = $this->user('alice');
        $token = $this->token($alice);
        $this->repository->save($token);
        $id = $token->getId();

        $this->repository->remove($token);
        $this->entityManager->clear();

        self::assertNull($this->repository->find($id));
    }

    public function testDeletingTheUserDeletesItsTokens(): void
    {
        $alice = $this->user('alice');
        $token = $this->token($alice);
        $this->repository->save($token);
        $id = $token->getId();

        $this->entityManager->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$alice->getId()->toRfc4122()]);
        $this->entityManager->clear();

        self::assertNull($this->repository->find($id));
    }

    public function testRemovingWithoutFlushLeavesTheDecisionToTheCaller(): void
    {
        $alice = $this->user('alice');
        $token = $this->token($alice);
        $this->repository->save($token);

        $this->repository->remove($token, flush: false);
        $this->entityManager->clear();
        self::assertNotNull($this->repository->find($token->getId()), 'rien n’est écrit tant que l’appelant ne valide pas');
    }

    public function testTheReadRightsComeBackAsIssued(): void
    {
        $token = new ApiToken(Uuid::v7(), $this->user('alice'), 'CI', self::VERIFIER, 'v1:AAAAwrapped', [Uuid::v7()->toRfc4122()], new \DateTimeImmutable('2026-11-01T10:00:00Z'), null, permissions: new \App\ApiToken\TokenPermissions(readSchema: true));
        $this->repository->save($token);
        $this->entityManager->clear();

        $permissions = $this->repository->find($token->getId())?->getPermissions();

        self::assertNotNull($permissions);
        self::assertTrue($permissions->readSchema);
        self::assertFalse($permissions->export);
    }

    public function testATokenIssuedBeforeTheReadRightsKeepsExactlyItsRights(): void
    {
        $owner = $this->user('alice');
        $id = Uuid::v7();
        $connection = $this->entityManager->getConnection();
        // La ligne telle qu'une version antérieure l'écrivait : aucune des colonnes des droits de lecture.
        $connection->executeStatement(
            'INSERT INTO api_token (id, name, verifier, wrapped_key, connection_ids, created_at, expires_at, owner_id, write_data, write_schema, create_connections, diagnostic, single_step, all_connections) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id->toRfc4122(), 'Old', self::VERIFIER, 'v1:AAAAwrapped', '[]', '2026-11-01 10:00:00', '2026-12-01 10:00:00', $owner->getId()->toRfc4122(), true, false, true, true, false, true],
            [5 => \Doctrine\DBAL\ParameterType::STRING, 8 => \Doctrine\DBAL\ParameterType::BOOLEAN, 9 => \Doctrine\DBAL\ParameterType::BOOLEAN, 10 => \Doctrine\DBAL\ParameterType::BOOLEAN, 11 => \Doctrine\DBAL\ParameterType::BOOLEAN, 12 => \Doctrine\DBAL\ParameterType::BOOLEAN, 13 => \Doctrine\DBAL\ParameterType::BOOLEAN],
        );
        $this->entityManager->clear();

        $permissions = $this->repository->find($id)?->getPermissions();

        self::assertNotNull($permissions);
        self::assertEquals(new \App\ApiToken\TokenPermissions(writeData: true, createConnections: true, diagnostic: true), $permissions);
    }
}

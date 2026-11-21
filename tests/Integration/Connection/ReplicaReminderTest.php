<?php

declare(strict_types=1);

namespace App\Tests\Integration\Connection;

use App\Connection\ConnectionManager;
use App\Connection\Exception\ReplicaUnavailableException;
use App\Connection\ReplicaProbe;
use App\Connection\ReplicaReminder;
use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Diagnostics\Vitess\ReplicaAvailability;
use App\Crypto\DerivedKey;
use App\Crypto\KeyDerivation;
use App\Crypto\SecretCipher;
use App\Entity\User;
use App\Repository\DatabaseConnectionRepository;
use App\Repository\UserRepository;
use App\Security\AuthPasswordHasher;
use App\Tests\Integration\Persistence\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[Group('integration')]
final class ReplicaReminderTest extends DatabaseTestCase
{
    private ConnectionManager $manager;
    private ReplicaReminder $reminder;
    private MockClock $clock;
    private User $alice;
    private DerivedKey $key;
    private ReplicaAvailability $answer = ReplicaAvailability::Missing;
    private int $probes = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $repository = self::getContainer()->get(DatabaseConnectionRepository::class);
        self::assertInstanceOf(DatabaseConnectionRepository::class, $repository);
        $this->clock = new MockClock('2026-11-21 10:00:00 UTC');
        $this->manager = new ConnectionManager(new SecretCipher(), $repository, $this->clock);
        $probe = new class($this) implements ReplicaProbe {
            public function __construct(private readonly ReplicaReminderTest $test)
            {
            }

            public function inspect(User $user, Engine $engine, ConnectionParameters $parameters): ReplicaAvailability
            {
                return $this->test->probed();
            }
        };
        $this->reminder = new ReplicaReminder($this->manager, $probe, $this->clock);
        $this->alice = $this->user('alice');
        $this->key = new DerivedKey(random_bytes(DerivedKey::LENGTH));
    }

    public function probed(): ReplicaAvailability
    {
        ++$this->probes;

        return $this->answer;
    }

    private function user(string $name): User
    {
        $user = new User($name.'@example.test', $name, (new AuthPasswordHasher(opsLimit: 3, memLimit: 16384))->hash('correct horse battery'), (new KeyDerivation(opsLimit: 1, memLimit: 8192))->newParameters(), new \DateTimeImmutable('2026-10-29T12:00:00Z'));
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $users->save($user);

        return $user;
    }

    private function saved(bool $replica = false, string $name = 'Vitess'): Uuid
    {
        return $this->manager->create($this->alice, $this->key, $name, Engine::MySql, new ConnectionParameters('vtgate.example.test', 15306, 'shop', 'app', 'pw-unique-9', readReplica: $replica))->getId();
    }

    private function armed(): Uuid
    {
        $id = $this->saved();
        $this->answer = ReplicaAvailability::Missing;
        $this->reminder->arm($this->alice, $id, Engine::MySql, $this->manager->reveal($this->alice, $this->key, $id));

        return $id;
    }

    public function testAConnectionWithoutReplicaIsWatchedFromItsCreation(): void
    {
        $id = $this->armed();

        $connection = $this->manager->get($this->alice, $id);
        self::assertEquals(new \DateTimeImmutable('2026-11-21 10:00:00 UTC'), $connection->getReplicaCheckedAt());
        self::assertFalse($connection->isReplicaFound());
    }

    public function testAConnectionThatAlreadyHasOrDoesNotNeedAReplicaIsNotWatched(): void
    {
        $id = $this->saved();
        foreach ([ReplicaAvailability::Available, ReplicaAvailability::NotVitess, ReplicaAvailability::Unknown] as $answer) {
            $this->answer = $answer;
            $this->reminder->arm($this->alice, $id, Engine::MySql, $this->manager->reveal($this->alice, $this->key, $id));
            self::assertNull($this->manager->get($this->alice, $id)->getReplicaCheckedAt(), $answer->value);
        }

        $reading = $this->saved(true, 'Lecture');
        $this->probes = 0;
        $this->reminder->arm($this->alice, $reading, Engine::MySql, $this->manager->reveal($this->alice, $this->key, $reading));
        self::assertSame(0, $this->probes, 'une connexion qui lit déjà sur réplica n’est pas cherchée');
        self::assertNull($this->manager->get($this->alice, $reading)->getReplicaCheckedAt());
    }

    public function testNothingIsAskedBeforeAWeekHasPassed(): void
    {
        $id = $this->armed();
        $this->probes = 0;
        $this->answer = ReplicaAvailability::Available;

        $this->clock->sleep(6 * 86400);

        self::assertFalse($this->reminder->check($this->alice, $this->key, $id));
        self::assertSame(0, $this->probes);
    }

    public function testAfterAWeekTheReplicaIsLookedForAndAnAppearedOneIsReported(): void
    {
        $id = $this->armed();
        $this->clock->sleep(7 * 86400);
        $this->answer = ReplicaAvailability::Available;

        self::assertTrue($this->reminder->check($this->alice, $this->key, $id));

        $this->probes = 0;
        self::assertTrue($this->reminder->check($this->alice, $this->key, $id), 'le bandeau reste tant qu’on n’a pas répondu');
        self::assertSame(0, $this->probes, 'sans chercher de nouveau');
    }

    public function testAfterAWeekWithoutReplicaTheNextLookIsAWeekLater(): void
    {
        $id = $this->armed();
        $this->clock->sleep(7 * 86400);
        $this->answer = ReplicaAvailability::Missing;

        self::assertFalse($this->reminder->check($this->alice, $this->key, $id));
        $this->probes = 0;
        $this->clock->sleep(86400);
        self::assertFalse($this->reminder->check($this->alice, $this->key, $id));
        self::assertSame(0, $this->probes);
    }

    public function testAnUnreadableServerIsLookedAtAgainNextTime(): void
    {
        $id = $this->armed();
        $this->clock->sleep(7 * 86400);
        $this->answer = ReplicaAvailability::Unknown;
        self::assertFalse($this->reminder->check($this->alice, $this->key, $id));

        $this->answer = ReplicaAvailability::Available;
        self::assertTrue($this->reminder->check($this->alice, $this->key, $id));
    }

    public function testAServerThatIsNotVitessAnymoreStopsTheWatch(): void
    {
        $id = $this->armed();
        $this->clock->sleep(7 * 86400);
        $this->answer = ReplicaAvailability::NotVitess;

        self::assertFalse($this->reminder->check($this->alice, $this->key, $id));
        self::assertNull($this->manager->get($this->alice, $id)->getReplicaCheckedAt());
    }

    public function testLaterPostponesTheReminderByAWeek(): void
    {
        $id = $this->armed();
        $this->clock->sleep(7 * 86400);
        $this->answer = ReplicaAvailability::Available;
        self::assertTrue($this->reminder->check($this->alice, $this->key, $id));

        $this->reminder->later($this->alice, $id);

        self::assertFalse($this->reminder->check($this->alice, $this->key, $id));
        $this->clock->sleep(7 * 86400);
        self::assertTrue($this->reminder->check($this->alice, $this->key, $id), 'une semaine après, le réplica est toujours là');
    }

    public function testEnablingTurnsReplicaReadingOnAndEndsTheWatch(): void
    {
        $id = $this->armed();
        $this->answer = ReplicaAvailability::Available;

        $this->reminder->enable($this->alice, $this->key, $id);

        self::assertTrue($this->manager->reveal($this->alice, $this->key, $id)->readReplica);
        self::assertNull($this->manager->get($this->alice, $id)->getReplicaCheckedAt());
        self::assertSame('Vitess', $this->manager->get($this->alice, $id)->getName());
    }

    public function testEnablingIsRefusedWhenTheReplicaIsGone(): void
    {
        $id = $this->armed();
        $this->answer = ReplicaAvailability::Missing;

        try {
            $this->reminder->enable($this->alice, $this->key, $id);
            self::fail('enable must refuse');
        } catch (ReplicaUnavailableException $refused) {
            self::assertSame('no_replica', $refused->reason);
        }
        self::assertFalse($this->manager->reveal($this->alice, $this->key, $id)->readReplica);
    }

    public function testAnotherAccountCannotReadOrChangeIt(): void
    {
        $id = $this->armed();
        $bob = $this->user('bob');

        $this->expectException(\App\Connection\Exception\ConnectionNotFoundException::class);
        $this->reminder->check($bob, $this->key, $id);
    }
}

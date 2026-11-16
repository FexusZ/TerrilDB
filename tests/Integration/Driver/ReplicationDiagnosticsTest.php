<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Diagnostics\Availability;
use App\Core\Diagnostics\Replication\ReplicationRole;
use App\Core\Diagnostics\Replication\ThreadState;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\TargetConnection;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use App\Driver\PostgreSql\PostgreSqlDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * La réplication lue sur les vrais moteurs. Les cibles habituelles n'ont PAS de réplica : on y vérifie « pas de réplication
 * configurée » et le refus de droit. Une paire source / réplica réelle est lue seulement si TARGET_REPLICA_MYSQL_URL ou
 * TARGET_REPLICA_MARIADB_URL la désigne (compte administrateur du réplica) ; sinon ces cas sont sautés.
 */
#[Group('integration')]
final class ReplicationDiagnosticsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sqlTargets(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL', 'REPLICATION CLIENT'];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL', 'REPLICATION CLIENT'];
        yield 'MariaDB' => ['TARGET_MARIADB_URL', 'SLAVE MONITOR'];
        yield 'MariaDB 13' => ['TARGET_MARIADB13_URL', 'SLAVE MONITOR'];
    }

    #[DataProvider('postgresTargets')]
    public function testPostgreSqlWithoutReplicationSaysSo(string $envVar): void
    {
        $target = $this->openPostgreSql($envVar);

        $report = $target->diagnostics()->replication()->replication();
        $target->close();

        self::assertSame(Availability::Available, $report->status->availability);
        self::assertSame(ReplicationRole::None, $report->role);
        self::assertSame(0, $report->replicaCount);
        self::assertSame([], $report->alerts());
        self::assertSame(Availability::Available, $report->extras->slots?->status->availability);
        self::assertSame([], $report->extras->slots->slots);
        self::assertSame([], $report->extras->replicas?->replicas);
    }

    /**
     * Primaire d'une paire jetable (TARGET_REPLICATED_POSTGRES_URL, avec son réplica en streaming) : le slot du réplica est actif
     * et ne retient rien ; un slot créé ici et jamais consommé est inactif et retient du WAL, jusqu'à son retrait.
     */
    public function testAPostgreSqlSlotThatNobodyReadsIsInactiveAndRetainsWal(): void
    {
        $target = $this->openPostgreSql('TARGET_REPLICATED_POSTGRES_URL');
        $admin = (new DbalConnector())->connect(Engine::PostgreSql, TargetDatabase::parameters('TARGET_REPLICATED_POSTGRES_URL'));
        $slot = 'tdb_slot_'.bin2hex(random_bytes(4));
        $table = 'tdb_wal_'.bin2hex(random_bytes(4));
        $admin->executeStatement(\sprintf("SELECT pg_create_physical_replication_slot('%s', true)", $slot));
        try {
            $admin->executeStatement(\sprintf("CREATE TABLE %s AS SELECT g, repeat('x', 1000) AS pad FROM generate_series(1, 5000) g", $table));
            $admin->executeStatement('SELECT pg_switch_wal()');

            $report = $target->diagnostics()->replication()->replication();

            $slots = $report->extras->slots;
            self::assertSame(Availability::Available, $slots?->status->availability);
            $mine = array_values(array_filter($slots->slots, static fn ($s): bool => $s->name === $slot));
            self::assertCount(1, $mine);
            self::assertFalse($mine[0]->active);
            self::assertGreaterThan(0, $mine[0]->retainedBytes);
            self::assertContains($slot, array_map(static fn ($a): string => $a->channel, array_filter($report->alerts(), static fn ($a): bool => 'slot_inactive' === $a->code->value)));
            $others = array_filter($slots->slots, static fn ($s): bool => $s->name !== $slot);
            self::assertNotSame([], $others, 'The replica slot is expected.');
            foreach ($others as $other) {
                self::assertTrue($other->active);
            }
        } finally {
            $admin->executeStatement(\sprintf("SELECT pg_drop_replication_slot('%s')", $slot));
            $admin->executeStatement(\sprintf('DROP TABLE IF EXISTS %s', $table));
            $admin->close();
            $target->close();
        }
    }

    public function testAStreamingPostgreSqlReplicaIsSeenFromItsSource(): void
    {
        $target = $this->openPostgreSql('TARGET_REPLICATED_POSTGRES_URL');

        $report = $target->diagnostics()->replication()->replication();
        $target->close();

        self::assertSame(ReplicationRole::Source, $report->role);
        $links = $report->extras->replicas;
        self::assertSame(Availability::Available, $links?->status->availability);
        self::assertCount(1, $links->replicas);
        self::assertTrue($links->replicas[0]->connected);
        self::assertSame('async', $links->replicas[0]->syncState);
        self::assertSame([], $report->alerts());
    }

    public function testPostgreSqlAccountWithoutTheRightGetsAStateNotAnError(): void
    {
        $parameters = TargetDatabase::parameters('TARGET_POSTGRES_URL');
        $user = 'tdb_repl_'.bin2hex(random_bytes(4));
        $password = bin2hex(random_bytes(12));
        $admin = (new DbalConnector())->connect(Engine::PostgreSql, $parameters);
        $admin->executeStatement(\sprintf("CREATE ROLE %s LOGIN PASSWORD '%s'", $user, $password));
        try {
            $reduced = $this->openPostgreSql('TARGET_POSTGRES_URL', new ConnectionParameters($parameters->host, $parameters->port, $parameters->database, $user, $password));
            $report = $reduced->diagnostics()->replication()->replication();
            $status = $reduced->diagnostics()->replication()->status();
            $reduced->close();

            self::assertSame(Availability::PermissionDenied, $status->availability);
            self::assertSame('pg_read_all_stats', $status->privilege);
            self::assertSame(Availability::PermissionDenied, $report->status->availability);
            self::assertSame([], $report->alerts());
        } finally {
            $admin->executeStatement(\sprintf('DROP ROLE IF EXISTS %s', $user));
        }
    }

    public function testAnActualPostgreSqlReplicaIsRead(): void
    {
        $target = $this->openPostgreSql('TARGET_REPLICA_POSTGRES_URL');

        $report = $target->diagnostics()->replication()->replication();
        $target->close();

        self::assertSame(ReplicationRole::Replica, $report->role);
        self::assertSame(ThreadState::Running, $report->channels[0]->receiver);
        self::assertSame(ThreadState::Running, $report->channels[0]->applier);
        self::assertNotNull($report->channels[0]->received);
        // En restauration pg_current_wal_lsn() échouerait : la position rejouée sert de référence.
        self::assertSame(Availability::Available, $report->extras->slots?->status->availability);
        self::assertSame([], $report->extras->replicas?->replicas);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function postgresTargets(): iterable
    {
        yield 'PostgreSQL' => ['TARGET_POSTGRES_URL'];
    }

    private function openPostgreSql(string $envVar, ?ConnectionParameters $parameters = null): TargetConnection
    {
        try {
            return (new PostgreSqlDriver(new DbalConnector()))->connect(Engine::PostgreSql, $parameters ?? TargetDatabase::parameters($envVar));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped(\sprintf('%s is unreachable.', $envVar));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sqlTargetNames(): iterable
    {
        foreach (self::sqlTargets() as $name => [$envVar]) {
            yield $name => [$envVar];
        }
    }

    private function open(string $envVar, ?ConnectionParameters $parameters = null): TargetConnection
    {
        $engine = TargetDatabase::engine($envVar);
        try {
            return (new MySqlDriver(new DbalConnector()))->connect($engine, $parameters ?? TargetDatabase::parameters($envVar));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped(\sprintf('%s is unreachable.', $envVar));
        }
    }

    #[DataProvider('sqlTargetNames')]
    public function testAServerWithoutReplicationSaysSoWithoutErrorNorAlert(string $envVar): void
    {
        $target = $this->open($envVar);

        $report = $target->diagnostics()->replication()->replication();
        $target->close();

        self::assertSame(Availability::Available, $report->status->availability);
        self::assertSame(ReplicationRole::None, $report->role);
        self::assertSame([], $report->channels);
        self::assertSame([], $report->alerts());
    }

    #[DataProvider('sqlTargets')]
    public function testAccountWithoutTheRightGetsAStateNotAnError(string $envVar, string $privilege): void
    {
        $parameters = TargetDatabase::parameters($envVar);
        $user = 'tdb_repl_'.bin2hex(random_bytes(4));
        $password = bin2hex(random_bytes(12));
        $admin = (new DbalConnector())->connect(TargetDatabase::engine($envVar), $parameters);
        $admin->executeStatement(\sprintf("CREATE USER '%s'@'%%' IDENTIFIED BY '%s'", $user, $password));
        $admin->executeStatement(\sprintf("GRANT SELECT ON shop.* TO '%s'@'%%'", $user));
        try {
            $reduced = $this->open($envVar, new ConnectionParameters($parameters->host, $parameters->port, $parameters->database, $user, $password));
            $status = $reduced->diagnostics()->replication()->status();
            $report = $reduced->diagnostics()->replication()->replication();
            $reduced->close();

            self::assertSame(Availability::PermissionDenied, $status->availability);
            self::assertSame($privilege, $status->privilege);
            self::assertSame(Availability::PermissionDenied, $report->status->availability);
            self::assertSame([], $report->alerts());
        } finally {
            $admin->executeStatement(\sprintf("DROP USER IF EXISTS '%s'@'%%'", $user));
        }
    }

    #[DataProvider('sqlTargetNames')]
    public function testTheCapabilityMapSaysReplicationIsReadable(string $envVar): void
    {
        $target = $this->open($envVar);

        self::assertSame(Availability::Available, $target->diagnostics()->capabilities()['replication']->availability);
        $target->close();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function replicas(): iterable
    {
        yield 'MySQL replica' => ['TARGET_REPLICA_MYSQL_URL'];
        yield 'MariaDB replica' => ['TARGET_REPLICA_MARIADB_URL'];
    }

    #[DataProvider('replicas')]
    public function testAnActualReplicaIsRead(string $envVar): void
    {
        $target = $this->open($envVar);

        $report = $target->diagnostics()->replication()->replication();
        $target->close();

        self::assertSame(Availability::Available, $report->status->availability);
        self::assertSame(ReplicationRole::Replica, $report->role);
        self::assertCount(1, $report->channels);
        self::assertSame(ThreadState::Running, $report->channels[0]->receiver);
        self::assertSame(ThreadState::Running, $report->channels[0]->applier);
        self::assertNotNull($report->channels[0]->lagSeconds);
        self::assertSame(0, $report->replicaCount);
    }

    /**
     * La cible Vitess (profil `vitess`, hors CI) : le vtgate parle le protocole MySQL et ne relaie pas la réplication des
     * tablets ; il répond comme un serveur sans réplication. Sauté sans TARGET_VITESS_URL ou si elle est injoignable.
     */
    public function testVitessGatewayAnswersAsAServerWithoutReplication(): void
    {
        try {
            $target = (new MySqlDriver(new DbalConnector()))->connect(Engine::MySql, TargetDatabase::parameters('TARGET_VITESS_URL'));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped('TARGET_VITESS_URL is unreachable.');
        }

        $report = $target->diagnostics()->replication()->replication();
        $target->close();

        self::assertSame(Availability::Available, $report->status->availability);
        self::assertSame(ReplicationRole::None, $report->role);
        self::assertSame([], $report->alerts());
    }
}

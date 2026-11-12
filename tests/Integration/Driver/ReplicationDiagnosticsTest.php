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

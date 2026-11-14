<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Core\Diagnostics\Availability;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\TargetConnection;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use App\Driver\PostgreSql\PostgreSqlDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Les sessions qui tiennent une table (et feraient donc attendre un ALTER), lues sur les vrais moteurs : une vraie transaction
 * ouverte sur une table jetable, jamais le schéma partagé.
 */
#[Group('integration')]
final class TableLockHoldersTest extends TestCase
{
    private ?LockScenario $scenario = null;

    protected function tearDown(): void
    {
        $this->scenario?->cleanup();
        $this->scenario = null;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield from TargetDatabase::relational();
    }

    private function open(string $envVar): TargetConnection
    {
        $engine = TargetDatabase::engine($envVar);
        $driver = Engine::PostgreSql === $engine ? new PostgreSqlDriver(new DbalConnector()) : new MySqlDriver(new DbalConnector());
        try {
            return $driver->connect($engine, TargetDatabase::parameters($envVar));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped(\sprintf('%s is unreachable (docker compose --profile targets up -d --wait).', $envVar));
        }
    }

    #[DataProvider('targets')]
    public function testAnOpenTransactionThatOnlyReadsTheTableIsAHolder(string $envVar): void
    {
        $this->open($envVar)->close();
        $this->scenario = $scenario = LockScenario::begin($envVar, 'read');
        $target = $this->open($envVar);

        $report = $target->diagnostics()->locks()->tableHolders($scenario->table);
        $target->close();

        if (Engine::MariaDb === TargetDatabase::engine($envVar)) {
            // performance_schema est éteint par défaut sur MariaDB : on le dit, on ne devine pas.
            self::assertSame(Availability::Disabled, $report->status->availability);
            self::assertSame('performance_schema_off', $report->status->reason);
            self::assertSame([], $report->holders);

            return;
        }
        self::assertSame(Availability::Available, $report->status->availability);
        $mine = array_values(array_filter($report->holders, static fn ($h): bool => $h->session === $scenario->holderId()));
        self::assertCount(1, $mine);
        self::assertTrue($mine[0]->sleeping);
        self::assertSame(TargetDatabase::parameters($envVar)->username, $mine[0]->user);
        self::assertGreaterThanOrEqual(0, $mine[0]->seconds);
    }

    #[DataProvider('targets')]
    public function testNobodyHoldsATableNobodyTouched(string $envVar): void
    {
        $this->open($envVar)->close();
        $this->scenario = $scenario = LockScenario::begin($envVar, 'read');
        $target = $this->open($envVar);

        $report = $target->diagnostics()->locks()->tableHolders('tdb_untouched_'.bin2hex(random_bytes(3)));
        $target->close();

        self::assertSame([], $report->holders);
        self::assertNotSame('', $scenario->holderId());
    }

    #[DataProvider('targets')]
    public function testOnceTheTransactionEndsTheTableIsFree(string $envVar): void
    {
        $this->open($envVar)->close();
        $this->scenario = $scenario = LockScenario::begin($envVar, 'read');
        $holder = $scenario->holderId();
        $scenario->release();
        $target = $this->open($envVar);

        $report = $target->diagnostics()->locks()->tableHolders($scenario->table);
        $target->close();

        self::assertSame([], array_filter($report->holders, static fn ($h): bool => $h->session === $holder));
    }
}

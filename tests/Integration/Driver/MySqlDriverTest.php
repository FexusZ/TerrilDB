<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\Driver;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Guard\StatementKind;
use App\Core\Query\Exception\QueryTimeoutException;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryOptions;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class MySqlDriverTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL'];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL'];
        yield 'MariaDB' => ['TARGET_MARIADB_URL'];
        yield 'MariaDB 13' => ['TARGET_MARIADB13_URL'];
    }

    public function testServesMySqlAndMariaDb(): void
    {
        $driver = new MySqlDriver(new DbalConnector());

        self::assertInstanceOf(Driver::class, $driver);
        self::assertSame([Engine::MySql, Engine::MariaDb], $driver->engines());
    }

    public function testRefusesAnotherEngine(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new MySqlDriver(new DbalConnector()))->connect(Engine::PostgreSql, TargetDatabase::parameters('TARGET_MYSQL_URL'));
    }

    #[DataProvider('targets')]
    public function testOpensAConnectionThatIntrospectsTheTarget(string $envVar): void
    {
        $driver = new MySqlDriver(new DbalConnector());
        $engine = TargetDatabase::engine($envVar);

        try {
            $connection = $driver->connect($engine, TargetDatabase::parameters($envVar));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped(\sprintf('%s is unreachable (docker compose --profile targets up -d --wait).', $envVar));
        }

        self::assertSame($engine, $connection->engine);
        self::assertCount(7, $connection->introspector()->listTables());
        $connection->close();
    }

    #[DataProvider('targets')]
    public function testAWrongPasswordIsRefused(string $envVar): void
    {
        $this->expectException(TargetConnectionFailedException::class);

        (new MySqlDriver(new DbalConnector()))->connect(TargetDatabase::engine($envVar), TargetDatabase::parameters($envVar)->withPassword('wrong-secret-value'));
    }

    /**
     * Vitess n'applique pas SET SESSION MAX_EXECUTION_TIME : le driver le détecte (@@version) et met le délai dans la requête.
     */
    public function testATimeoutIsEnforcedThroughVitess(): void
    {
        $connection = $this->openVitess();
        $started = microtime(true);

        try {
            $connection->runner()->run('SELECT SLEEP(10)', StatementKind::Read, new QueryOptions(QueryMode::ReadOnly, 1000, 10));
            self::fail('Expected a timeout.');
        } catch (QueryTimeoutException) {
            self::assertLessThan(5.0, microtime(true) - $started);
        }

        $result = $connection->runner()->run('SELECT 1', StatementKind::Read, new QueryOptions(QueryMode::ReadOnly, 1000, 10));
        self::assertSame([[1]], $result->rows, 'la connexion reste utilisable');
        $connection->close();
    }

    public function testAWriteTimeoutIsEnforcedThroughVitess(): void
    {
        $connection = $this->openVitess();

        $started = microtime(true);
        try {
            $connection->runner()->run('UPDATE categories SET name = name WHERE SLEEP(10) = 0', StatementKind::Write, new QueryOptions(QueryMode::ReadWrite, 1000, 10));
            self::fail('Expected a timeout.');
        } catch (QueryTimeoutException) {
            self::assertLessThan(5.0, microtime(true) - $started);
        } finally {
            $connection->close();
        }
    }

    public function testAQueryUnderTheTimeoutStillSucceedsThroughVitess(): void
    {
        $connection = $this->openVitess();

        $result = $connection->runner()->run('SELECT SLEEP(0.2) AS s', StatementKind::Read, new QueryOptions(QueryMode::ReadOnly, 3000, 10));

        self::assertSame([[0]], $result->rows);
        $connection->close();
    }

    private function openVitess(): \App\Core\Driver\TargetConnection
    {
        try {
            return (new MySqlDriver(new DbalConnector()))->connect(Engine::MySql, TargetDatabase::parameters('TARGET_VITESS_URL'));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped('TARGET_VITESS_URL is unreachable (docker compose --profile vitess up -d --wait target-vitess).');
        }
    }
}

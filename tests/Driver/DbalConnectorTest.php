<?php

declare(strict_types=1);

namespace App\Tests\Driver;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Connection\SshJump;
use App\Driver\DbalConnector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DbalConnectorTest extends TestCase
{
    public function testItDoesNotOpenAnEngineThatHasNoDbalDriver(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new DbalConnector())->connect(Engine::ClickHouse, new ConnectionParameters('db.example.test', 8123, 'shop', 'reader', 'not-a-real-secret'));
    }

    public function testPostgreSqlThroughATunnelUsesTheSocketDirectoryAndTheTargetPort(): void
    {
        $parameters = (new ConnectionParameters('db.behind', 5432, 'shop', 'reader', 'not-a-real-secret'))->withSocket('/run/tunnels/0123456789abcdef0123456789abcdef/.s.PGSQL.5432');

        $options = (new DbalConnector())->options(Engine::PostgreSql, $parameters);

        self::assertSame('/run/tunnels/0123456789abcdef0123456789abcdef', $options['host'] ?? null);
        self::assertSame(5432, $options['port'] ?? null);
        self::assertArrayNotHasKey('unix_socket', $options);
    }

    #[DataProvider('mysqlEngines')]
    public function testMySqlThroughATunnelUsesTheUnixSocketAndNoHost(Engine $engine): void
    {
        $parameters = (new ConnectionParameters('db.behind', 3306, 'shop', 'reader', 'not-a-real-secret'))->withSocket('/run/tunnels/0123456789abcdef0123456789abcdef/tunnel.sock');

        $options = (new DbalConnector())->options($engine, $parameters);

        self::assertSame('/run/tunnels/0123456789abcdef0123456789abcdef/tunnel.sock', $options['unix_socket'] ?? null);
        self::assertArrayNotHasKey('host', $options);
        self::assertArrayNotHasKey('port', $options);
    }

    /**
     * @return iterable<string, array{Engine}>
     */
    public static function mysqlEngines(): iterable
    {
        yield 'mysql' => [Engine::MySql];
        yield 'mariadb' => [Engine::MariaDb];
    }

    public function testWithoutATunnelTheAddressIsUsedAsBefore(): void
    {
        $options = (new DbalConnector())->options(Engine::MySql, (new ConnectionParameters('db.example.test', 3306, 'shop', 'reader', 'not-a-real-secret'))->withAddress('203.0.113.9'));

        self::assertSame('203.0.113.9', $options['host'] ?? null);
        self::assertSame(3306, $options['port'] ?? null);
    }

    public function testAJumpThatWasNotTunnelledIsNeverReachedDirectly(): void
    {
        $key = "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAAfaux\n-----END OPENSSH PRIVATE KEY-----\n";
        $parameters = new ConnectionParameters('db.behind', 5432, 'shop', 'reader', 'not-a-real-secret', transport: new SshJump('bastion.example.test', 22, 'jump', $key));

        $this->expectException(\LogicException::class);

        (new DbalConnector())->connect(Engine::PostgreSql, $parameters);
    }
}

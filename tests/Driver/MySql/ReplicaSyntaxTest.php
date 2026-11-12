<?php

declare(strict_types=1);

namespace App\Tests\Driver\MySql;

use App\Core\Connection\Engine;
use App\Driver\MySql\ReplicaSyntax;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReplicaSyntaxTest extends TestCase
{
    #[DataProvider('versions')]
    public function testSaysWhetherTheReplicaSyntaxExists(Engine $engine, string $version, bool $expected): void
    {
        self::assertSame($expected, ReplicaSyntax::exists($engine, $version));
    }

    /**
     * @return iterable<string, array{Engine, string, bool}>
     */
    public static function versions(): iterable
    {
        yield 'mysql 8.4' => [Engine::MySql, '8.4.13', true];
        yield 'mysql 26' => [Engine::MySql, '26.10.0', true];
        yield 'mysql 8.0.22' => [Engine::MySql, '8.0.22', true];
        yield 'mysql 8.0.21' => [Engine::MySql, '8.0.21', false];
        yield 'mysql 5.7' => [Engine::MySql, '5.7.44-log', false];
        yield 'vitess mysql 8.0.35' => [Engine::MySql, '8.0.35-Vitess', true];
        yield 'mariadb 11.8' => [Engine::MariaDb, '11.8.9-MariaDB-ubu2404-log', true];
        yield 'mariadb 13' => [Engine::MariaDb, '13.0.1-MariaDB', true];
        yield 'mariadb 10.5.1' => [Engine::MariaDb, '10.5.1-MariaDB', true];
        yield 'mariadb 10.4' => [Engine::MariaDb, '10.4.32-MariaDB', false];
        yield 'mariadb with the replication prefix' => [Engine::MariaDb, '5.5.5-10.11.6-MariaDB', true];
        yield 'mariadb old with the prefix' => [Engine::MariaDb, '5.5.5-10.4.32-MariaDB', false];
        yield 'unreadable' => [Engine::MySql, 'weird', false];
    }
}

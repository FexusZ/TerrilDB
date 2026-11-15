<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\TargetConnection;
use App\Core\Schema\Change\DdlStatement;
use App\Core\Schema\LockReason;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tables à versionnement système (MariaDB, WITH SYSTEM VERSIONING) : l'introspecteur les reconnaît et verrouille leurs colonnes
 * de période ; les autres moteurs n'ont pas cette notion. Tables jetables.
 */
#[Group('integration')]
final class VersionedTableTest extends TestCase
{
    private ?TargetConnection $target = null;

    /** @var list<string> */
    private array $tables = [];

    protected function tearDown(): void
    {
        foreach ($this->tables as $table) {
            try {
                $this->raw(\sprintf('DROP TABLE IF EXISTS `%s`', $table));
            } catch (\Throwable) {
            }
        }
        $this->target?->close();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mariaDb(): iterable
    {
        yield 'MariaDB' => ['TARGET_MARIADB_URL'];
        yield 'MariaDB 13' => ['TARGET_MARIADB13_URL'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mySql(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL'];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL'];
    }

    #[DataProvider('mariaDb')]
    public function testATableWithImplicitPeriodColumnsIsVersionedAndShowsNoHiddenColumn(string $env): void
    {
        $table = $this->create($env, 'CREATE TABLE `%s` (id int NOT NULL PRIMARY KEY, name varchar(40) NULL) ENGINE=InnoDB WITH SYSTEM VERSIONING');
        \assert(null !== $this->target);

        $definition = $this->target->introspector()->describeTable($table);

        self::assertTrue($definition->versioned);
        self::assertSame(['id', 'name'], array_map(static fn ($c): string => $c->name, $definition->columns));
        self::assertSame(['id'], $definition->primaryKey()?->columns);
    }

    #[DataProvider('mariaDb')]
    public function testExplicitPeriodColumnsAreLockedAsSystemPeriod(string $env): void
    {
        $table = $this->create($env, 'CREATE TABLE `%s` (id int NOT NULL PRIMARY KEY, name varchar(40) NULL, rs timestamp(6) GENERATED ALWAYS AS ROW START, re timestamp(6) GENERATED ALWAYS AS ROW END, PERIOD FOR SYSTEM_TIME (rs, re)) ENGINE=InnoDB WITH SYSTEM VERSIONING');
        \assert(null !== $this->target);

        $definition = $this->target->introspector()->describeTable($table);

        self::assertTrue($definition->versioned);
        self::assertSame(LockReason::SystemPeriod, $definition->column('rs')?->lock);
        self::assertSame(LockReason::SystemPeriod, $definition->column('re')?->lock);
        self::assertNull($definition->column('name')?->lock);
    }

    #[DataProvider('mariaDb')]
    public function testAnOrdinaryTableIsNotVersioned(string $env): void
    {
        $table = $this->create($env, 'CREATE TABLE `%s` (id int NOT NULL PRIMARY KEY, name varchar(40) NULL) ENGINE=InnoDB');
        \assert(null !== $this->target);

        self::assertFalse($this->target->introspector()->describeTable($table)->versioned);
    }

    #[DataProvider('mySql')]
    public function testMySqlHasNoSuchNotion(string $env): void
    {
        $table = $this->create($env, 'CREATE TABLE `%s` (id int NOT NULL PRIMARY KEY, name varchar(40) NULL) ENGINE=InnoDB');
        \assert(null !== $this->target);

        self::assertFalse($this->target->introspector()->describeTable($table)->versioned);
    }

    private function create(string $env, string $sql): string
    {
        $engine = TargetDatabase::engine($env);
        try {
            $this->target = (new MySqlDriver(new DbalConnector()))->connect($engine, TargetDatabase::parameters($env));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped('The target is unreachable (docker compose --profile targets up -d --wait).');
        }
        self::assertContains($engine, [Engine::MySql, Engine::MariaDb]);
        $table = $this->tables[] = 'tdb_ver_'.bin2hex(random_bytes(4));
        $this->raw(\sprintf($sql, $table));

        return $table;
    }

    private function raw(string $sql): void
    {
        \assert(null !== $this->target);
        $this->target->ddl()->execute([new DdlStatement($sql, 'raw', 'raw', false)], false);
    }
}

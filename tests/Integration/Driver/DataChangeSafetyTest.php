<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Core\Data\DataChangeService;
use App\Core\Data\DataDraft;
use App\Core\Data\DataWarning;
use App\Core\Data\RowInsert;
use App\Core\Data\RowUpdate;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\TargetConnection;
use App\Core\Schema\Change\DdlStatement;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use App\Driver\PostgreSql\PostgreSqlDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Ce que l'aperçu de l'édition dit du moteur de stockage et des déclencheurs, sur de vraies tables jetables.
 */
#[Group('integration')]
final class DataChangeSafetyTest extends TestCase
{
    private ?TargetConnection $target = null;
    private Engine $engine;
    private string $env = '';

    /** @var list<string> */
    private array $tables = [];

    protected function tearDown(): void
    {
        if (null === $this->target) {
            return;
        }
        $this->target->close();
        $this->target = $this->connect();
        foreach ($this->tables as $table) {
            try {
                $this->raw(\sprintf('DROP TABLE IF EXISTS %s', $this->q($table)));
            } catch (\Throwable) {
            }
        }
        $this->target?->close();
    }

    private function connect(): TargetConnection
    {
        $parameters = TargetDatabase::parameters($this->env);

        return Engine::PostgreSql === $this->engine
            ? (new PostgreSqlDriver(new DbalConnector()))->connect($this->engine, $parameters)
            : (new MySqlDriver(new DbalConnector()))->connect($this->engine, $parameters);
    }

    private function open(string $env): void
    {
        $this->env = $env;
        $this->engine = TargetDatabase::engine($env);
        try {
            $this->target = $this->connect();
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped('The target is unreachable (docker compose --profile targets up -d --wait).');
        }
    }

    private function q(string $name): string
    {
        return Engine::PostgreSql === $this->engine ? '"'.$name.'"' : '`'.$name.'`';
    }

    private function raw(string $sql): void
    {
        \assert(null !== $this->target);
        $this->target->ddl()->execute([new DdlStatement($sql, 'raw', 'raw', false)], false);
    }

    private function scratch(string $suffix = ''): string
    {
        $name = 'tdb_safe_'.bin2hex(random_bytes(4));
        $this->tables[] = $name;
        $this->raw(\sprintf('CREATE TABLE %s (id integer NOT NULL PRIMARY KEY, name varchar(40) NOT NULL)%s', $this->q($name), $suffix));
        $this->raw(\sprintf("INSERT INTO %s (id, name) VALUES (1, 'alpha')", $this->q($name)));

        return $name;
    }

    /**
     * @return list<string>
     */
    private function codes(string $table, DataDraft $draft): array
    {
        return array_map(static fn (DataWarning $w): string => $w->code, $this->warnings($table, $draft));
    }

    /**
     * @return list<DataWarning>
     */
    private function warnings(string $table, ?DataDraft $draft = null): array
    {
        \assert(null !== $this->target);

        return (new DataChangeService())->preview($this->target, $table, $draft ?? $this->draft())->warnings;
    }

    private function draft(): DataDraft
    {
        return new DataDraft([new RowUpdate(['id' => 1, 'name' => 'alpha'], ['name' => 'beta'])], [new RowInsert(['id' => 2, 'name' => 'gamma'])], []);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mysqlFamily(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL'];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL'];
        yield 'MariaDB' => ['TARGET_MARIADB_URL'];
        yield 'MariaDB 13' => ['TARGET_MARIADB13_URL'];
    }

    #[DataProvider('mysqlFamily')]
    public function testTheStorageEngineIsRead(string $env): void
    {
        $this->open($env);
        \assert(null !== $this->target);
        $innodb = $this->scratch(' ENGINE=InnoDB');
        $myisam = $this->scratch(' ENGINE=MyISAM');

        $a = $this->target->introspector()->describeTable($innodb);
        $b = $this->target->introspector()->describeTable($myisam);

        self::assertSame('InnoDB', $a->storageEngine);
        self::assertTrue($a->transactional);
        self::assertSame('MyISAM', $b->storageEngine);
        self::assertFalse($b->transactional);
    }

    #[DataProvider('mysqlFamily')]
    public function testANonTransactionalEngineIsWarnedAboutAndInnoDbIsNot(string $env): void
    {
        $this->open($env);
        $myisam = $this->scratch(' ENGINE=MyISAM');
        $memory = $this->scratch(' ENGINE=MEMORY');
        $innodb = $this->scratch(' ENGINE=InnoDB');

        self::assertSame(['not_transactional'], $this->codes($myisam, $this->draft()));
        self::assertSame(['not_transactional'], $this->codes($memory, $this->draft()));
        self::assertSame([], $this->codes($innodb, $this->draft()));
    }

    #[DataProvider('mysqlFamily')]
    public function testTheTriggersOfTheTableAreCounted(string $env): void
    {
        $this->open($env);
        \assert(null !== $this->target);
        $table = $this->scratch(' ENGINE=InnoDB');
        $this->raw(\sprintf('CREATE TRIGGER %s AFTER INSERT ON %s FOR EACH ROW SET @tdb_x = 1', $this->q($table.'_t'), $this->q($table)));

        try {
            self::assertSame(1, $this->target?->introspector()->describeTable($table)->triggerCount);
            $warnings = $this->warnings($table);
            self::assertCount(1, $warnings);
            self::assertSame('triggers', $warnings[0]->code);
            self::assertSame(1, $warnings[0]->value);
        } finally {
            $this->raw(\sprintf('DROP TRIGGER IF EXISTS %s', $this->q($table.'_t')));
        }
    }

    public function testPostgreSqlCountsItsTriggersButNotItsInternalOnes(): void
    {
        $this->open('TARGET_POSTGRES_URL');
        \assert(null !== $this->target);
        $table = $this->scratch();
        self::assertSame(0, $this->target->introspector()->describeTable($table)->triggerCount);
        self::assertNull($this->target->introspector()->describeTable($table)->storageEngine);
        $fn = $table.'_f';
        $this->raw(\sprintf('CREATE FUNCTION %s() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NEW; END $$', $this->q($fn)));
        $this->raw(\sprintf('CREATE TRIGGER %s BEFORE INSERT ON %s FOR EACH ROW EXECUTE FUNCTION %s()', $this->q($table.'_t'), $this->q($table), $this->q($fn)));

        try {
            self::assertSame(1, $this->target?->introspector()->describeTable($table)->triggerCount);
            self::assertSame(['triggers'], $this->codes($table, $this->draft()));
        } finally {
            $this->raw(\sprintf('DROP TABLE IF EXISTS %s', $this->q($table)));
            $this->raw(\sprintf('DROP FUNCTION IF EXISTS %s()', $this->q($fn)));
        }
    }
}

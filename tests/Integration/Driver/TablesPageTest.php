<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Schema\SchemaIntrospector;
use App\Core\Schema\TablePageRequest;
use App\Driver\ClickHouse\ClickHouseHttp;
use App\Driver\ClickHouse\ClickHouseIntrospector;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlIntrospector;
use App\Driver\PostgreSql\PostgreSqlIntrospector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * La pagination se fait côté moteur (clé de tri à l'octet, LIMIT) : on parcourt une base de plus de 200 tables.
 */
#[Group('integration')]
final class TablesPageTest extends TestCase
{
    private const TARGETS = ['TARGET_MYSQL_URL', 'TARGET_MARIADB_URL', 'TARGET_POSTGRES_URL', 'TARGET_CLICKHOUSE_URL'];

    /** @var array<string, bool> */
    private static array $ready = [];

    public static function setUpBeforeClass(): void
    {
        foreach (self::TARGETS as $envVar) {
            try {
                ManyTablesFixture::create($envVar);
                self::$ready[$envVar] = true;
            } catch (\Throwable) {
                self::$ready[$envVar] = false;
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::TARGETS as $envVar) {
            if (self::$ready[$envVar] ?? false) {
                ManyTablesFixture::drop($envVar);
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL'];
        yield 'MariaDB' => ['TARGET_MARIADB_URL'];
        yield 'PostgreSQL' => ['TARGET_POSTGRES_URL'];
        yield 'ClickHouse' => ['TARGET_CLICKHOUSE_URL'];
    }

    private function introspector(string $envVar): SchemaIntrospector
    {
        if (!(self::$ready[$envVar] ?? false)) {
            self::markTestSkipped(\sprintf('%s is unreachable (docker compose --profile targets up -d --wait).', $envVar));
        }
        $engine = TargetDatabase::engine($envVar);
        $parameters = TargetDatabase::parameters($envVar)->withDatabase(ManyTablesFixture::DATABASE);

        return match ($envVar) {
            'TARGET_POSTGRES_URL' => new PostgreSqlIntrospector((new DbalConnector())->connect($engine, $parameters)),
            'TARGET_CLICKHOUSE_URL' => new ClickHouseIntrospector(new ClickHouseHttp(HttpClient::create(), $parameters), ManyTablesFixture::DATABASE),
            default => new MySqlIntrospector((new DbalConnector())->connect($engine, $parameters)),
        };
    }

    /**
     * @return list<string> tous les noms, page après page
     */
    private function walk(SchemaIntrospector $introspector, int $limit, ?string $search = null): array
    {
        $names = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = $introspector->listTablesPage(new TablePageRequest($limit, $cursor, $search));
            self::assertLessThanOrEqual($limit, \count($page->tables));
            foreach ($page->tables as $table) {
                $names[] = $table->name;
            }
            $cursor = $page->nextCursor;
            self::assertLessThan(1000, ++$pages, 'the pagination must end');
        } while (null !== $cursor);

        return $names;
    }

    #[DataProvider('targets')]
    public function testPagesCoverEveryTableOnceInByteOrder(string $envVar): void
    {
        $walked = $this->walk($this->introspector($envVar), 100);

        self::assertSame(ManyTablesFixture::names(), $walked);
    }

    #[DataProvider('targets')]
    public function testALimitThatDoesNotDivideTheTotalStillCoversEverything(string $envVar): void
    {
        self::assertSame(ManyTablesFixture::names(), $this->walk($this->introspector($envVar), 7));
    }

    #[DataProvider('targets')]
    public function testPagesHaveTheAskedSizeAndTheLastOneEndsTheWalk(string $envVar): void
    {
        $introspector = $this->introspector($envVar);

        $first = $introspector->listTablesPage(new TablePageRequest(100));
        self::assertCount(100, $first->tables);
        self::assertNotNull($first->nextCursor);
        self::assertSame($first->tables[99]->name, $first->nextCursor);

        $second = $introspector->listTablesPage(new TablePageRequest(100, $first->nextCursor));
        self::assertCount(100, $second->tables);
        $third = $introspector->listTablesPage(new TablePageRequest(100, $second->nextCursor));
        self::assertCount(\count(ManyTablesFixture::names()) - 200, $third->tables);
        self::assertNull($third->nextCursor);
    }

    #[DataProvider('targets')]
    public function testAnExactMultipleDoesNotInventAnEmptyLastPage(string $envVar): void
    {
        $total = \count(ManyTablesFixture::names());
        $page = $this->introspector($envVar)->listTablesPage(new TablePageRequest($total));

        self::assertCount($total, $page->tables);
        self::assertNull($page->nextCursor);
    }

    #[DataProvider('targets')]
    public function testOnlyTheFirstPageCarriesTheTotal(string $envVar): void
    {
        $introspector = $this->introspector($envVar);

        $first = $introspector->listTablesPage(new TablePageRequest(50));
        self::assertSame(\count(ManyTablesFixture::names()), $first->total);
        $next = $introspector->listTablesPage(new TablePageRequest(50, $first->nextCursor));
        self::assertNull($next->total);
    }

    #[DataProvider('targets')]
    public function testTheOrderIsByBytesWhateverTheCollationOfTheEngine(string $envVar): void
    {
        $first = $this->introspector($envVar)->listTablesPage(new TablePageRequest(3));

        self::assertSame(['B_up', 'a_low', 't_001'], array_map(static fn ($table): string => $table->name, $first->tables));
    }

    #[DataProvider('targets')]
    public function testTheCursorNeedNotBeATableThatExists(string $envVar): void
    {
        $page = $this->introspector($envVar)->listTablesPage(new TablePageRequest(2, 't_100x'));

        self::assertSame(['t_101', 't_102'], array_map(static fn ($table): string => $table->name, $page->tables));
    }

    #[DataProvider('targets')]
    public function testSearchIsACaseInsensitiveSubstring(string $envVar): void
    {
        $introspector = $this->introspector($envVar);

        self::assertSame(['t_001', 't_002', 't_003', 't_004', 't_005', 't_006', 't_007', 't_008', 't_009'], $this->walk($introspector, 4, 'T_00'));
        self::assertSame(['a_low'], $this->walk($introspector, 10, 'A_LOW'));
        self::assertSame([], $this->walk($introspector, 10, 'nothing-like-this'));
    }

    #[DataProvider('targets')]
    public function testSearchTreatsUnderscoreAndPercentAsPlainCharacters(string $envVar): void
    {
        $introspector = $this->introspector($envVar);

        self::assertSame(['zz_a_b'], $this->walk($introspector, 10, 'a_b'));
        self::assertSame(['zz_a%b'], $this->walk($introspector, 10, '%'));
        self::assertSame(['zz_aXb'], $this->walk($introspector, 10, 'aXb'));
    }

    #[DataProvider('targets')]
    public function testSearchAndTotalGoTogether(string $envVar): void
    {
        $page = $this->introspector($envVar)->listTablesPage(new TablePageRequest(5, null, 't_1'));

        // t_100 à t_199 : cent tables.
        self::assertSame(100, $page->total);
        self::assertCount(5, $page->tables);
        self::assertNotNull($page->nextCursor);
    }

    #[DataProvider('targets')]
    public function testAHostileSearchIsOnlyEverAValue(string $envVar): void
    {
        $introspector = $this->introspector($envVar);

        self::assertSame([], $this->walk($introspector, 10, "'; DROP TABLE t_001; --"));
        self::assertCount(\count(ManyTablesFixture::names()), $this->walk($introspector, 100));
    }
}

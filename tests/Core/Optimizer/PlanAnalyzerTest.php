<?php

declare(strict_types=1);

namespace App\Tests\Core\Optimizer;

use App\Core\Optimizer\MySqlPlanParser;
use App\Core\Optimizer\PlanAnalyzer;
use App\Core\Optimizer\PlanFinding;
use App\Core\Optimizer\PlanKind;
use App\Core\Optimizer\PlanNode;
use App\Core\Optimizer\PlanParser;
use App\Core\Optimizer\PostgreSqlPlanParser;
use App\Core\Optimizer\QueryPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlanAnalyzerTest extends TestCase
{
    /**
     * @return list<PlanFinding>
     */
    private static function findings(PlanParser $parser, string $fixture, bool $limited = false): array
    {
        $json = (string) file_get_contents(__DIR__.'/../../Fixtures/plans/'.$fixture.'.json');

        return (new PlanAnalyzer())->analyze($parser->parse($json), $limited);
    }

    /**
     * @param list<PlanFinding> $findings
     *
     * @return list<PlanFinding>
     */
    private static function rule(array $findings, string $rule): array
    {
        return array_values(array_filter($findings, static fn (PlanFinding $f): bool => $f->rule === $rule));
    }

    /**
     * @return iterable<string, array{PlanParser, string}>
     */
    public static function bigSorts(): iterable
    {
        yield 'MySQL 8.4' => [new MySqlPlanParser(), 'mysql-sort'];
        yield 'MySQL 26 (JSON v2)' => [new MySqlPlanParser(), 'mysql26-sort'];
        yield 'MariaDB' => [new MySqlPlanParser(), 'mariadb-sort'];
        yield 'PostgreSQL' => [new PostgreSqlPlanParser(), 'postgres-sort'];
    }

    #[DataProvider('bigSorts')]
    public function testASortOfManyRowsWithoutLimitIsFlagged(PlanParser $parser, string $fixture): void
    {
        $sorts = self::rule(self::findings($parser, $fixture), 'large_sort');

        self::assertCount(1, $sorts);
        self::assertSame('order_items', $sorts[0]->relation);
        self::assertSame(7000, $sorts[0]->rows);
    }

    #[DataProvider('bigSorts')]
    public function testNoSortFindingWhenTheStatementIsLimited(PlanParser $parser, string $fixture): void
    {
        self::assertSame([], self::rule(self::findings($parser, $fixture, limited: true), 'large_sort'));
    }

    public function testPostgreSqlSortUnderLimitIsATopNAndStaysQuiet(): void
    {
        self::assertSame([], self::rule(self::findings(new PostgreSqlPlanParser(), 'postgres-sort-limit'), 'large_sort'));
    }

    /**
     * @return iterable<string, array{PlanParser, string}>
     */
    public static function smallSorts(): iterable
    {
        yield 'MySQL 8.4' => [new MySqlPlanParser(), 'mysql-sort-small'];
        yield 'MySQL 26 (JSON v2)' => [new MySqlPlanParser(), 'mysql26-sort-small'];
        yield 'MariaDB' => [new MySqlPlanParser(), 'mariadb-sort-small'];
        yield 'PostgreSQL' => [new PostgreSqlPlanParser(), 'postgres-sort-small'];
    }

    #[DataProvider('smallSorts')]
    public function testASortOfASmallTableIsNeverFlagged(PlanParser $parser, string $fixture): void
    {
        self::assertSame([], self::findings($parser, $fixture));
    }

    public function testASortWithoutEstimateStaysQuiet(): void
    {
        $plan = new QueryPlan(new PlanNode(PlanKind::Sort, 'Sort', children: [new PlanNode(PlanKind::IndexScan, 'Index Scan', 'orders', rows: 90000)]), null);

        self::assertSame([], (new PlanAnalyzer())->analyze($plan));
    }
}

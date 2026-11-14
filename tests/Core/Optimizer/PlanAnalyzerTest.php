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

    /**
     * @return iterable<string, array{PlanParser, string}>
     */
    public static function wildcards(): iterable
    {
        yield 'MySQL 8.4' => [new MySqlPlanParser(), 'mysql-like-wildcard'];
        yield 'MySQL 26 (JSON v2)' => [new MySqlPlanParser(), 'mysql26-like-wildcard'];
        yield 'MariaDB' => [new MySqlPlanParser(), 'mariadb-like-wildcard'];
        yield 'PostgreSQL' => [new PostgreSqlPlanParser(), 'postgres-like-wildcard'];
    }

    #[DataProvider('wildcards')]
    public function testALikeStartingWithAWildcardOnABigScanExplainsWhyNoIndexHelps(PlanParser $parser, string $fixture): void
    {
        $findings = self::findings($parser, $fixture);

        self::assertCount(1, $findings, 'le conseil « ajoutez un index » du parcours complet serait faux ici');
        self::assertSame('leading_wildcard', $findings[0]->rule);
        self::assertSame('customers', $findings[0]->relation);
        self::assertSame(1000, $findings[0]->rows);
        self::assertStringContainsString('%a%', (string) $findings[0]->condition);
    }

    /**
     * @return iterable<string, array{PlanParser, string}>
     */
    public static function quietLikes(): iterable
    {
        yield 'MySQL 8.4 préfixe' => [new MySqlPlanParser(), 'mysql-like-prefix'];
        yield 'MySQL 26 préfixe' => [new MySqlPlanParser(), 'mysql26-like-prefix'];
        yield 'MariaDB préfixe' => [new MySqlPlanParser(), 'mariadb-like-prefix'];
        yield 'PostgreSQL préfixe' => [new PostgreSqlPlanParser(), 'postgres-like-prefix'];
        yield 'MySQL 8.4 petite table' => [new MySqlPlanParser(), 'mysql-like-wildcard-small'];
        yield 'MySQL 26 petite table' => [new MySqlPlanParser(), 'mysql26-like-wildcard-small'];
        yield 'MariaDB petite table' => [new MySqlPlanParser(), 'mariadb-like-wildcard-small'];
        yield 'PostgreSQL petite table' => [new PostgreSqlPlanParser(), 'postgres-like-wildcard-small'];
    }

    #[DataProvider('quietLikes')]
    public function testAPrefixLikeOrASmallTableIsNeverFlagged(PlanParser $parser, string $fixture): void
    {
        self::assertSame([], self::findings($parser, $fixture));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherConditions(): iterable
    {
        yield 'comparaison' => ['(`shop`.`orders`.`status` = \'%x\')'];
        yield 'like sans joker initial' => ['(o.name like \'a%\')'];
        yield 'not like' => ['(o.name not like \'abc\')'];
    }

    #[DataProvider('otherConditions')]
    public function testOnlyALikeWithAnInitialWildcardIsRecognised(string $condition): void
    {
        $scan = new PlanNode(PlanKind::FullScan, 'ALL', 'o', rows: 5000, condition: $condition, fullScan: true);

        self::assertSame(['full_scan'], array_map(static fn (PlanFinding $f): string => $f->rule, (new PlanAnalyzer())->analyze(new QueryPlan($scan, null))));
    }

    public function testASortWithoutEstimateStaysQuiet(): void
    {
        $plan = new QueryPlan(new PlanNode(PlanKind::Sort, 'Sort', children: [new PlanNode(PlanKind::IndexScan, 'Index Scan', 'orders', rows: 90000)]), null);

        self::assertSame([], (new PlanAnalyzer())->analyze($plan));
    }
}

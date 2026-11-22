<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics\Vitess;

use App\Core\Diagnostics\Vitess\StreamHit;
use App\Core\Diagnostics\Vitess\StreamSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le champ `source` de `_vt.vreplication` est un message BinlogSource en texte (format de la documentation de Vitess) : il dit quelles
 * tables du keyspace d'origine nourrissent un flux (Materialize, MoveTables…). Un ALTER sur l'une d'elles n'est pas propagé.
 */
final class StreamSourceTest extends TestCase
{
    public function testAMaterializeReadsTheTablesOfItsSelect(): void
    {
        $source = StreamSource::parse('keyspace:"commerce" shard:"0" filter:{rules:{match:"sales_by_sku" filter:"select sku, count(*) as orders, sum(price) as revenue from corder group by sku"}} on_ddl:EXEC_IGNORE');

        self::assertNotNull($source);
        self::assertSame('commerce', $source->keyspace);
        self::assertTrue($source->feeds('corder'));
        self::assertTrue($source->feeds('CORDER'), 'sans casse');
        self::assertFalse($source->feeds('sales_by_sku'), 'la cible n\'est pas une source');
        self::assertFalse($source->feeds('customer'));
    }

    public function testJoinsAndCommaListsAndQuotedNamesAreAllSources(): void
    {
        $source = StreamSource::parse('keyspace:"shop" shard:"-80" filter:{rules:{match:"report" filter:"select a.x from `orders` a join shop.items i on i.o = a.id, customers c where c.id = a.c"}}');

        self::assertNotNull($source);
        self::assertTrue($source->feeds('orders'));
        self::assertTrue($source->feeds('items'));
        self::assertTrue($source->feeds('customers'));
        self::assertFalse($source->feeds('report'));
    }

    public function testAMoveTablesRuleWithoutSelectFeedsItsOwnTable(): void
    {
        $source = StreamSource::parse('keyspace:"commerce" shard:"0" filter:{rules:{match:"customer"} rules:{match:"corder" filter:"select * from corder"}}');

        self::assertNotNull($source);
        self::assertTrue($source->feeds('customer'));
        self::assertTrue($source->feeds('corder'));
    }

    public function testARegexRuleFeedsWhateverItMatches(): void
    {
        $source = StreamSource::parse('keyspace:"shop" shard:"0" filter:{rules:{match:"/log_.*/"}}');

        self::assertNotNull($source);
        self::assertTrue($source->feeds('log_2026'));
        self::assertFalse($source->feeds('orders'));
    }

    public function testEscapedQuotesInTheSelectDoNotBreakTheReading(): void
    {
        $source = StreamSource::parse('keyspace:"shop" shard:"0" filter:{rules:{match:"t" filter:"select concat(\"a\", x) from base where y = \"z\""}}');

        self::assertNotNull($source);
        self::assertTrue($source->feeds('base'));
    }

    #[DataProvider('unreadable')]
    public function testSomethingThatIsNotASourceIsNull(string $text): void
    {
        self::assertNull(StreamSource::parse($text));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadable(): iterable
    {
        yield 'empty' => [''];
        yield 'no keyspace' => ['shard:"0" filter:{rules:{match:"t"}}'];
        yield 'garbage' => ["\x00\x01binary"];
    }

    public function testASourceWithoutRulesFeedsNothing(): void
    {
        $source = StreamSource::parse('keyspace:"shop" shard:"0"');

        self::assertNotNull($source);
        self::assertFalse($source->feeds('orders'));
    }

    #[DataProvider('statements')]
    public function testAStatementTouchesTheSourceWhenItNamesIt(string $sql, bool $touches): void
    {
        $source = StreamSource::parse('keyspace:"commerce" shard:"0" filter:{rules:{match:"sales" filter:"select sku from corder"}}');

        self::assertNotNull($source);
        self::assertSame($touches, $source->touchedBy($sql));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function statements(): iterable
    {
        yield 'alter' => ['ALTER TABLE corder ADD COLUMN note text', true];
        yield 'quoted and qualified' => ['ALTER TABLE `commerce`.`corder` DROP COLUMN x', true];
        yield 'drop' => ['DROP TABLE corder', true];
        yield 'rename' => ['RENAME TABLE corder TO corder_old', true];
        yield 'truncate' => ['TRUNCATE corder', true];
        yield 'another table' => ['ALTER TABLE customer ADD COLUMN x int', false];
        yield 'the name only in a string' => ["ALTER TABLE customer COMMENT 'corder is the source'", false];
        yield 'the name only in a comment' => ['ALTER TABLE customer /* corder */ ADD COLUMN x int', false];
        yield 'a longer name' => ['ALTER TABLE corder_archive ADD COLUMN x int', false];
    }

    public function testTheWorkflowAndTheTargetTableOfAMaterializeAreKnown(): void
    {
        $source = StreamSource::parse('keyspace:"commerce" shard:"0" filter:{rules:{match:"sales_by_sku" filter:"select sku, count(*) from corder group by sku"} rules:{match:"other" filter:"select id from customer"}}', 'sales');

        self::assertNotNull($source);
        self::assertSame('sales', $source->workflow);
        self::assertSame(['sales_by_sku'], $source->targetsOf('corder'));
        self::assertSame(['other'], $source->targetsOf('customer'));
        self::assertSame([], $source->targetsOf('sales_by_sku'), 'la cible n\'est la source de rien');
    }

    public function testAMoveTablesRuleHasNoOtherTargetNameThanItsOwn(): void
    {
        $source = StreamSource::parse('keyspace:"commerce" shard:"0" filter:{rules:{match:"customer"}}', 'move');

        self::assertNotNull($source);
        self::assertSame(['customer'], $source->targetsOf('customer'));
    }

    public function testARegexRuleHasNoNameableTargetAndAnUnnamedWorkflowIsEmpty(): void
    {
        $source = StreamSource::parse('keyspace:"commerce" shard:"0" filter:{rules:{match:"/cust.*/"}}');

        self::assertNotNull($source);
        self::assertSame('', $source->workflow);
        self::assertTrue($source->feeds('customer'));
        self::assertSame([], $source->targetsOf('customer'));
    }

    public function testTheHitsOfAStatementNameTheWorkflowTheSourceAndItsTargets(): void
    {
        $source = StreamSource::parse('keyspace:"commerce" shard:"0" filter:{rules:{match:"sales_by_sku" filter:"select sku from corder"}}', 'sales');

        self::assertNotNull($source);
        self::assertEquals([new StreamHit('sales', 'corder', ['sales_by_sku'])], $source->hitsIn('ALTER TABLE `corder` ADD COLUMN note text'));
        self::assertSame([], $source->hitsIn('ALTER TABLE customer ADD COLUMN note text'));
        self::assertEquals([new StreamHit('sales', 'corder', ['sales_by_sku'])], $source->hitsIn('DROP TABLE corder, corder'), 'une seule fois par table');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics\Vitess;

use App\Core\Diagnostics\Vitess\SkippedTablet;
use App\Core\Diagnostics\Vitess\VitessTablet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VitessTabletTest extends TestCase
{
    public function testBuildsTheTargetVtgateUnderstandsFromTheTabletRow(): void
    {
        $tablet = VitessTablet::fromRow(['Cell' => 'test', 'Keyspace' => 'shop', 'Shard' => '-80', 'TabletType' => 'REPLICA', 'State' => 'SERVING', 'Alias' => 'test-0000000102']);

        self::assertNotNull($tablet);
        self::assertSame('shop:-80@replica|test-0000000102', $tablet->target());
        self::assertSame('test-0000000102', $tablet->alias);
        self::assertTrue($tablet->serving);
    }

    public function testTheTypeIsLowercasedAndPrimaryKeepsItsName(): void
    {
        $tablet = VitessTablet::fromRow(['Cell' => 'zone1', 'Keyspace' => 'shop', 'Shard' => '0', 'TabletType' => 'PRIMARY', 'State' => 'SERVING', 'Alias' => 'zone1-0000000100']);

        self::assertSame('shop:0@primary|zone1-0000000100', $tablet?->target());
    }

    public function testATabletNotServingIsListedButFlagged(): void
    {
        $tablet = VitessTablet::fromRow(['Cell' => 'test', 'Keyspace' => 'shop', 'Shard' => '0', 'TabletType' => 'RDONLY', 'State' => 'NOT_SERVING', 'Alias' => 'test-0000000003']);

        self::assertNotNull($tablet);
        self::assertFalse($tablet->serving);
    }

    /**
     * @param array<string, string|null> $row
     */
    #[DataProvider('unusableRows')]
    public function testARowThatCannotBeTargetedIsLeftOut(array $row): void
    {
        self::assertNull(VitessTablet::fromRow($row));
    }

    /**
     * @return iterable<string, array{array<string, string|null>}>
     */
    public static function unusableRows(): iterable
    {
        $base = ['Cell' => 'test', 'Keyspace' => 'shop', 'Shard' => '0', 'TabletType' => 'REPLICA', 'State' => 'SERVING', 'Alias' => 'test-0000000002'];

        yield 'type without a target (backup)' => [['TabletType' => 'BACKUP'] + $base];
        yield 'alias with a separator' => [['Alias' => 'test-1|x'] + $base];
        yield 'alias with a quote' => [['Alias' => "test-1'"] + $base];
        yield 'empty alias' => [['Alias' => ''] + $base];
        yield 'keyspace with a colon' => [['Keyspace' => 'a:b'] + $base];
        yield 'shard with an arobase' => [['Shard' => '0@primary'] + $base];
        yield 'missing column' => [['Alias' => null] + $base];
    }

    public function testAnAliasIsRecognisedByItsShapeOnly(): void
    {
        self::assertTrue(VitessTablet::isAlias('zone1-0000000100'));
        self::assertTrue(VitessTablet::isAlias('us_east-12'));
        self::assertFalse(VitessTablet::isAlias(''));
        self::assertFalse(VitessTablet::isAlias('zone1'));
        self::assertFalse(VitessTablet::isAlias('zone1-100|x'));
        self::assertFalse(VitessTablet::isAlias(str_repeat('a', 80).'-1'));
    }

    public function testATabletTargetIsRecognisedByItsShape(): void
    {
        self::assertTrue(VitessTablet::isTarget('shop:0@replica|test-0000000002'));
        self::assertTrue(VitessTablet::isTarget('shop:-80@primary|zone1-0000000100'));
        self::assertFalse(VitessTablet::isTarget('shop'));
        self::assertFalse(VitessTablet::isTarget('shop@replica'));
        self::assertFalse(VitessTablet::isTarget('shop:0@replica'));
        self::assertFalse(VitessTablet::isTarget('shop:0@replica|x'));
        self::assertFalse(VitessTablet::isTarget(''));
    }

    public function testASkippedRowIsExplainedByItsTypeWhenTheTypeIsAKnownOne(): void
    {
        $skipped = VitessTablet::skipped(['Cell' => 'test', 'Keyspace' => 'shop', 'Shard' => '0', 'TabletType' => 'backup', 'State' => 'SERVING', 'Alias' => 'test-0000000009']);

        self::assertSame(SkippedTablet::TYPE_NOT_TARGETABLE, $skipped->reason);
        self::assertSame('BACKUP', $skipped->type);
    }

    public function testAnUnknownTypeIsNeverEchoedBack(): void
    {
        $skipped = VitessTablet::skipped(['Cell' => 'test', 'Keyspace' => 'shop', 'Shard' => '0', 'TabletType' => "<script>alert('x')</script>", 'State' => 'SERVING', 'Alias' => 'test-0000000009']);

        self::assertSame(SkippedTablet::TYPE_NOT_TARGETABLE, $skipped->reason);
        self::assertNull($skipped->type, 'une valeur du serveur qui n\'est pas un type connu ne sort jamais');
    }

    public function testARowWithAStrangeNameOrMissingColumnIsExplainedWithoutItsValues(): void
    {
        $base = ['Cell' => 'test', 'Keyspace' => 'shop', 'Shard' => '0', 'TabletType' => 'REPLICA', 'State' => 'SERVING', 'Alias' => 'test-0000000002'];

        $name = VitessTablet::skipped(['Alias' => 'test-1|x'] + $base);
        self::assertSame(SkippedTablet::UNEXPECTED_NAME, $name->reason);
        self::assertSame('REPLICA', $name->type);

        $missing = VitessTablet::skipped(['Alias' => null] + $base);
        self::assertSame(SkippedTablet::UNREADABLE, $missing->reason);
    }

    public function testEveryRowThatFromRowRefusesHasASkipReason(): void
    {
        foreach (self::unusableRows() as [$row]) {
            self::assertNull(VitessTablet::fromRow($row));
            self::assertContains(VitessTablet::skipped($row)->reason, [SkippedTablet::TYPE_NOT_TARGETABLE, SkippedTablet::UNEXPECTED_NAME, SkippedTablet::UNREADABLE]);
        }
    }
}

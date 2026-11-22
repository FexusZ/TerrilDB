<?php

declare(strict_types=1);

namespace App\Tests\Http\Json;

use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\ServerVariable;
use App\Core\Diagnostics\VariableReport;
use App\Core\Diagnostics\Vitess\SkippedTablet;
use App\Core\Diagnostics\Vitess\TabletComparer;
use App\Core\Diagnostics\Vitess\TabletListing;
use App\Core\Diagnostics\Vitess\TabletSnapshot;
use App\Core\Diagnostics\Vitess\VitessTablet;
use App\Http\Json\DiagnosticsPresenter;
use PHPUnit\Framework\TestCase;

final class TabletsPresenterTest extends TestCase
{
    public function testTheListKeepsItsHistoricalKeysAndAddsTheSkippedOnesAfterThem(): void
    {
        $body = DiagnosticsPresenter::tablets(new TabletListing(
            [new VitessTablet('test-0000000002', 'test', 'shop', '0', 'REPLICA', true)],
            [new SkippedTablet(SkippedTablet::TYPE_NOT_TARGETABLE, 'BACKUP'), new SkippedTablet(SkippedTablet::UNEXPECTED_NAME, null)],
        ));

        self::assertSame(['tablets', 'skipped'], array_keys($body));
        self::assertSame(['alias', 'cell', 'keyspace', 'shard', 'type', 'serving'], array_keys(self::first($body['tablets'])));
        self::assertSame([['reason' => 'type_not_targetable', 'type' => 'BACKUP'], ['reason' => 'unexpected_name', 'type' => null]], $body['skipped']);
    }

    public function testNothingSkippedIsAnEmptyList(): void
    {
        self::assertSame([], DiagnosticsPresenter::tablets(new TabletListing([]))['skipped']);
    }

    public function testTheComparisonShowsEachTabletItsReadingAndTheDifferingVariables(): void
    {
        $primary = new VitessTablet('test-0000000001', 'test', 'shop', '0', 'PRIMARY', true);
        $replica = new VitessTablet('test-0000000002', 'test', 'shop', '0', 'REPLICA', true);
        $variables = static fn (string $value): VariableReport => new VariableReport(DiagnosticStatus::available(), [new ServerVariable('max_connections', $value, '151', true, null, null, 'global')], false);
        $comparison = (new TabletComparer())->compare([
            new TabletSnapshot($primary, null, $variables('500'), null),
            new TabletSnapshot($replica, null, $variables('300'), null),
            new TabletSnapshot(new VitessTablet('test-0000000003', 'test', 'shop', '0', 'RDONLY', false), null, null, 'unreadable'),
        ]);

        $body = DiagnosticsPresenter::tabletComparison($comparison);

        self::assertSame(['tablets', 'differences', 'masked', 'truncated'], array_keys($body));
        $first = self::row($body, 0);
        $third = self::row($body, 2);
        self::assertSame('PRIMARY', $first['type']);
        self::assertNull($first['replication']);
        self::assertSame(1, $first['changedVariables']);
        self::assertSame('unreadable', $third['failure']);
        self::assertNull($third['changedVariables']);
        self::assertSame([['name' => 'max_connections', 'unit' => null, 'values' => ['test-0000000001' => '500', 'test-0000000002' => '300']]], $body['differences']);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<mixed>
     */
    private static function row(array $body, int $index): array
    {
        self::assertIsArray($body['tablets']);

        return self::first(\array_slice($body['tablets'], $index));
    }

    /**
     * @return array<mixed>
     */
    private static function first(mixed $list): array
    {
        self::assertIsArray($list);
        self::assertIsArray($list[0]);

        return $list[0];
    }
}

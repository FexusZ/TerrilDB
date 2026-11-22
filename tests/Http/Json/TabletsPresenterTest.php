<?php

declare(strict_types=1);

namespace App\Tests\Http\Json;

use App\Core\Diagnostics\Vitess\SkippedTablet;
use App\Core\Diagnostics\Vitess\TabletListing;
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

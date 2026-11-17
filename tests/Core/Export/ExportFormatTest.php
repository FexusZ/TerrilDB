<?php

declare(strict_types=1);

namespace App\Tests\Core\Export;

use App\Core\Export\ExportFormat;
use App\Core\Query\QueryResult;
use PHPUnit\Framework\TestCase;

final class ExportFormatTest extends TestCase
{
    public function testOnlyCsvAndJsonAreKnown(): void
    {
        self::assertSame(ExportFormat::Csv, ExportFormat::tryFrom('csv'));
        self::assertSame(ExportFormat::Json, ExportFormat::tryFrom('json'));
        self::assertSame(['csv', 'json'], array_map(static fn (ExportFormat $f): string => $f->value, ExportFormat::cases()));
    }

    public function testRendersWithTheMatchingExporter(): void
    {
        $result = QueryResult::rows(['id', 'name'], [[1, 'Maison']], false, 1);

        self::assertSame("id,name\r\n1,Maison\r\n", ExportFormat::Csv->render($result));
        self::assertSame("\xEF\xBB\xBFid,name\r\n1,Maison\r\n", ExportFormat::Csv->render($result, true));
        self::assertSame([['id' => 1, 'name' => 'Maison']], json_decode(ExportFormat::Json->render($result), true));
    }

    public function testEachFormatKnowsItsMediaTypeAndExtension(): void
    {
        self::assertSame('text/csv; charset=utf-8', ExportFormat::Csv->contentType());
        self::assertSame('application/json; charset=utf-8', ExportFormat::Json->contentType());
        self::assertSame('csv', ExportFormat::Csv->value);
    }
}

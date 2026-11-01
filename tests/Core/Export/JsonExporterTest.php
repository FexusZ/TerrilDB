<?php

declare(strict_types=1);

namespace App\Tests\Core\Export;

use App\Core\Export\JsonExporter;
use App\Core\Query\QueryResult;
use PHPUnit\Framework\TestCase;

final class JsonExporterTest extends TestCase
{
    public function testWritesAnArrayOfObjectsKeyedByColumn(): void
    {
        $json = (new JsonExporter())->export(QueryResult::rows(['id', 'name'], [[1, 'Maison'], [2, null]], false, 1));

        self::assertSame([['id' => 1, 'name' => 'Maison'], ['id' => 2, 'name' => null]], json_decode($json, true, flags: \JSON_THROW_ON_ERROR));
    }

    public function testAnEmptyResultIsAnEmptyArray(): void
    {
        self::assertSame('[]', trim((new JsonExporter())->export(QueryResult::rows(['id'], [], false, 1))));
    }

    public function testKeepsUnicodeAndSlashesReadable(): void
    {
        $json = (new JsonExporter())->export(QueryResult::rows(['a'], [['Élodie / 日本']], false, 1));

        self::assertStringContainsString('Élodie / 日本', $json);
    }

    public function testDuplicateColumnNamesAreMadeUnique(): void
    {
        $json = (new JsonExporter())->export(QueryResult::rows(['id', 'id', 'id'], [[1, 2, 3]], false, 1));

        self::assertSame([['id' => 1, 'id_2' => 2, 'id_3' => 3]], json_decode($json, true, flags: \JSON_THROW_ON_ERROR));
    }

    public function testBigIntegersStayStrings(): void
    {
        $json = (new JsonExporter())->export(QueryResult::rows(['big'], [['9223372036854775807']], false, 1));

        self::assertStringContainsString('"9223372036854775807"', $json);
    }

    public function testRefusesAWriteResult(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new JsonExporter())->export(QueryResult::affected(3, 1));
    }
}

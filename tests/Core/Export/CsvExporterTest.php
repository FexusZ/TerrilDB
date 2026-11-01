<?php

declare(strict_types=1);

namespace App\Tests\Core\Export;

use App\Core\Export\CsvExporter;
use App\Core\Query\QueryResult;
use PHPUnit\Framework\TestCase;

final class CsvExporterTest extends TestCase
{
    /**
     * @param list<string>                           $columns
     * @param list<list<bool|float|int|string|null>> $rows
     */
    private function export(array $columns, array $rows, bool $bom = false): string
    {
        return (new CsvExporter())->export(QueryResult::rows($columns, $rows, false, 1), $bom);
    }

    public function testWritesAHeaderAndRowsSeparatedByCrLf(): void
    {
        self::assertSame("id,name\r\n1,Maison\r\n2,Jardin\r\n", $this->export(['id', 'name'], [[1, 'Maison'], [2, 'Jardin']]));
    }

    public function testAnEmptyResultStillHasItsHeader(): void
    {
        self::assertSame("id,name\r\n", $this->export(['id', 'name'], []));
    }

    public function testQuotesWhatNeedsToBe(): void
    {
        $csv = $this->export(['a'], [['x,y'], ['say "hi"'], ["two\nlines"], ["cr\rhere"], [' padded '], ['plain']]);

        self::assertSame("a\r\n\"x,y\"\r\n\"say \"\"hi\"\"\"\r\n\"two\nlines\"\r\n\"cr\rhere\"\r\n\" padded \"\r\nplain\r\n", $csv);
    }

    public function testQuotesAHeaderThatNeedsIt(): void
    {
        self::assertSame("\"a,b\",c\r\n", $this->export(['a,b', 'c'], []));
    }

    public function testNullIsEmptyAndBooleansAreWords(): void
    {
        self::assertSame("a,b,c\r\n,true,false\r\n", $this->export(['a', 'b', 'c'], [[null, true, false]]));
    }

    public function testNumbersKeepTheirPlainForm(): void
    {
        self::assertSame("a,b,c\r\n12,1.5,-3\r\n", $this->export(['a', 'b', 'c'], [[12, 1.5, -3]]));
    }

    public function testNeverWritesAFloatInExponentForm(): void
    {
        $line = explode("\r\n", $this->export(['a'], [[0.00000012]]))[1];

        self::assertStringNotContainsString('E', strtoupper($line));
        self::assertEqualsWithDelta(0.00000012, (float) $line, 1e-12);
    }

    public function testKeepsUnicode(): void
    {
        self::assertSame("nom\r\nÉlodie — 日本\r\n", $this->export(['nom'], [['Élodie — 日本']]));
    }

    public function testCanStartWithAByteOrderMarkForSpreadsheets(): void
    {
        self::assertStringStartsWith("\xEF\xBB\xBF", $this->export(['a'], [], bom: true));
        self::assertStringStartsNotWith("\xEF\xBB\xBF", $this->export(['a'], []));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function formulas(): iterable
    {
        yield 'equals' => ['=1+1'];
        yield 'plus' => ['+1+1'];
        yield 'minus' => ['-1+1'];
        yield 'at' => ['@SUM(A1)'];
        yield 'tab' => ["\tcmd"];
        yield 'cr' => ["\rcmd"];
        yield 'a command' => ['=cmd|\' /C calc\'!A0'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('formulas')]
    public function testNeutralizesCellsATableCouldTreatAsAFormula(string $value): void
    {
        $cell = explode("\r\n", $this->export(['a'], [[$value]]), 2)[1];

        self::assertStringStartsWith("'", ltrim($cell, '"'));
    }

    public function testLeavesNegativeNumbersAndNumericStringsAlone(): void
    {
        self::assertSame("a,b,c,d\r\n-5,-1.5,-2.50,+3\r\n", $this->export(['a', 'b', 'c', 'd'], [[-5, -1.5, '-2.50', '+3']]));
    }

    public function testIgnoresTextThatMerelyContainsAnEqualsSign(): void
    {
        self::assertSame("a\r\nx=1\r\n", $this->export(['a'], [['x=1']]));
    }
}

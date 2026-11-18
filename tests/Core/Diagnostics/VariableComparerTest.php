<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics;

use App\Core\Diagnostics\Availability;
use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\ServerVariable;
use App\Core\Diagnostics\VariableComparer;
use App\Core\Diagnostics\VariableDiagnostics;
use App\Core\Diagnostics\VariableQuery;
use App\Core\Diagnostics\VariableReport;
use PHPUnit\Framework\TestCase;

final class VariableComparerTest extends TestCase
{
    /**
     * @param array<string, array{string|null, bool}> $variables nom => [valeur, modifiée]
     */
    private static function server(array $variables, ?DiagnosticStatus $status = null): VariableDiagnostics
    {
        $status ??= DiagnosticStatus::available();
        $list = [];
        foreach ($variables as $name => [$value, $changed]) {
            $list[] = new ServerVariable($name, $value, null, $changed, 'CONFIG', null, null);
        }

        return new class($list, $status) implements VariableDiagnostics {
            /** @param list<ServerVariable> $list */
            public function __construct(private array $list, private DiagnosticStatus $status)
            {
            }

            public function status(): DiagnosticStatus
            {
                return $this->status;
            }

            public function variables(VariableQuery $query): VariableReport
            {
                if (!$this->status->isAvailable()) {
                    return new VariableReport($this->status, [], false);
                }
                $rows = array_values(array_filter($this->list, static fn (ServerVariable $v): bool => (!$query->changedOnly || $v->changed) && ([] === $query->names || \in_array($v->name, $query->names, true))));

                return new VariableReport($this->status, \array_slice($rows, 0, $query->limit), \count($rows) > $query->limit);
            }
        };
    }

    public function testOnlyDifferingVariablesAreListed(): void
    {
        $a = self::server(['max_connections' => ['200', true], 'same' => ['1', true], 'untouched' => ['5', false]]);
        $b = self::server(['max_connections' => ['100', false], 'same' => ['1', true], 'untouched' => ['5', false]]);

        $result = (new VariableComparer())->compare($a, $b);

        self::assertSame(Availability::Available, $result->status->availability);
        self::assertCount(1, $result->differences);
        self::assertSame('max_connections', $result->differences[0]->name);
        self::assertSame('200', $result->differences[0]->valueA);
        self::assertSame('100', $result->differences[0]->valueB);
        self::assertTrue($result->differences[0]->changedA);
        self::assertFalse($result->differences[0]->changedB);
        self::assertFalse($result->truncated);
    }

    public function testTwoLongValuesThatOnlyDifferBeyondTheDisplayedPartAreADifference(): void
    {
        $common = str_repeat('a', 550);
        $comparison = (new VariableComparer())->compare(
            self::server(['sql_mode' => [$common.'X', true]]),
            self::server(['sql_mode' => [$common.'Y', true]]),
        );

        self::assertCount(1, $comparison->differences);
        $difference = $comparison->differences[0];
        self::assertSame('sql_mode', $difference->name);
        self::assertSame($difference->valueA, $difference->valueB, 'les deux textes montrés sont coupés avant la différence');
        self::assertTrue($difference->beyondDisplay);
    }

    public function testTwoIdenticalLongValuesAreNotADifference(): void
    {
        $long = str_repeat('a', 700);
        $comparison = (new VariableComparer())->compare(
            self::server(['sql_mode' => [$long, true]]),
            self::server(['sql_mode' => [$long, true]]),
        );

        self::assertSame([], $comparison->differences);
    }

    public function testAnOrdinaryDifferenceIsNotFlaggedAsBeyondTheDisplay(): void
    {
        $comparison = (new VariableComparer())->compare(
            self::server(['a' => ['1', true]]),
            self::server(['a' => ['2', true]]),
        );

        self::assertFalse($comparison->differences[0]->beyondDisplay);
    }

    public function testAVariableOnOneSideOnlyIsADifference(): void
    {
        $a = self::server(['only_a' => ['1', true]]);
        $b = self::server(['only_b' => ['2', true]]);

        $result = (new VariableComparer())->compare($a, $b);

        self::assertSame(['only_a', 'only_b'], array_map(static fn ($d): string => $d->name, $result->differences));
        self::assertTrue($result->differences[0]->presentA);
        self::assertFalse($result->differences[0]->presentB);
        self::assertNull($result->differences[0]->valueB);
    }

    public function testAMaskedVariableIsNeverComparedNorShown(): void
    {
        $a = self::server(['ssl_key' => ['/etc/a.pem', true], 'x' => ['1', true]]);
        $b = self::server(['ssl_key' => ['/etc/b.pem', true], 'x' => ['2', true]]);

        $result = (new VariableComparer())->compare($a, $b);

        self::assertSame(1, $result->masked);
        self::assertSame(['x'], array_map(static fn ($d): string => $d->name, $result->differences));
        self::assertStringNotContainsString('/etc/', json_encode($result->differences, \JSON_THROW_ON_ERROR));
    }

    public function testAValueThatLooksLikeASecretIsMaskedOnEitherSide(): void
    {
        $a = self::server(['plain' => ['host=h password=hunter2', true]]);
        $b = self::server(['plain' => ['host=h password=other', true]]);

        $result = (new VariableComparer())->compare($a, $b);

        self::assertSame(1, $result->masked);
        self::assertSame([], $result->differences);
    }

    public function testAnUnavailableSideSaysWhich(): void
    {
        $ok = self::server(['x' => ['1', true]]);
        $off = self::server([], DiagnosticStatus::disabled('performance_schema_off'));

        $result = (new VariableComparer())->compare($ok, $off);

        self::assertSame(Availability::Disabled, $result->status->availability);
        self::assertSame('b', $result->unavailableSide);
        self::assertSame([], $result->differences);
        self::assertSame('a', (new VariableComparer())->compare($off, $ok)->unavailableSide);
    }

    public function testTooManyChangedVariablesAreSaidTruncated(): void
    {
        $many = [];
        for ($i = 0; $i < 520; ++$i) {
            $many[\sprintf('v%03d', $i)] = ['1', true];
        }
        $other = array_map(static fn (array $v): array => ['2', true], $many);

        $result = (new VariableComparer())->compare(self::server($many), self::server($other));

        self::assertTrue($result->truncated);
        self::assertLessThanOrEqual(500, \count($result->differences));
        self::assertNotSame([], $result->differences);
    }
}

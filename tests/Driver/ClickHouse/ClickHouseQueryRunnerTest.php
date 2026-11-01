<?php

declare(strict_types=1);

namespace App\Tests\Driver\ClickHouse;

use App\Core\Connection\ConnectionParameters;
use App\Core\Guard\StatementKind;
use App\Core\Query\Exception\QueryFailedException;
use App\Core\Query\Exception\QueryTimeoutException;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryOptions;
use App\Driver\ClickHouse\ClickHouseHttp;
use App\Driver\ClickHouse\ClickHouseQueryRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ClickHouseQueryRunnerTest extends TestCase
{
    /** @var list<string> */
    private array $urls = [];

    private function runner(MockResponse $response): ClickHouseQueryRunner
    {
        $client = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            $this->urls[] = $url;

            return $response;
        });

        return new ClickHouseQueryRunner(new ClickHouseHttp($client, new ConnectionParameters('ch.example.test', 8123, 'shop', 'reader', 'not-a-real-secret')));
    }

    /**
     * @param list<list<mixed>> $data
     */
    private static function rows(array $data, string $type = 'String'): MockResponse
    {
        return new MockResponse(json_encode(['meta' => [['name' => 'a', 'type' => $type]], 'data' => $data], \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, string>
     */
    private function settings(): array
    {
        parse_str((string) parse_url($this->urls[0], \PHP_URL_QUERY), $query);
        $typed = [];
        foreach ($query as $name => $value) {
            $typed[(string) $name] = \is_string($value) ? $value : '';
        }

        return $typed;
    }

    public function testAReadReturnsItsColumnsAndRows(): void
    {
        $result = $this->runner(self::rows([['x'], ['y']]))->run('SELECT a FROM t', StatementKind::Read, new QueryOptions());

        self::assertTrue($result->returnsRows());
        self::assertSame(['a'], $result->columns);
        self::assertSame([['x'], ['y']], $result->rows);
        self::assertFalse($result->truncated);
    }

    public function testReadOnlyModeAsksTheEngineForReadOnly(): void
    {
        $this->runner(self::rows([]))->run('SELECT 1', StatementKind::Read, new QueryOptions(QueryMode::ReadOnly));

        self::assertSame('1', $this->settings()['readonly']);
    }

    public function testReadWriteModeDoesNotForceReadOnly(): void
    {
        $this->runner(new MockResponse('', ['response_headers' => ['X-ClickHouse-Summary: {"written_rows":"3"}']]))
            ->run('INSERT INTO t VALUES (1)', StatementKind::Write, new QueryOptions(QueryMode::ReadWrite));

        self::assertArrayNotHasKey('readonly', $this->settings());
    }

    public function testTheEngineGetsTheTimeoutAsSeconds(): void
    {
        $this->runner(self::rows([]))->run('SELECT 1', StatementKind::Read, new QueryOptions(timeoutMs: 1500));

        self::assertSame('1.5', $this->settings()['max_execution_time']);
    }

    public function testTheEngineStopsReadingJustPastTheRowLimit(): void
    {
        $this->runner(self::rows([]))->run('SELECT 1', StatementKind::Read, new QueryOptions(maxRows: 50));

        self::assertSame('51', $this->settings()['max_result_rows']);
        self::assertSame('break', $this->settings()['result_overflow_mode']);
        self::assertSame('51', $this->settings()['max_block_size']);
    }

    public function testRowsPastTheLimitAreDroppedAndFlagged(): void
    {
        $result = $this->runner(self::rows([['1'], ['2'], ['3'], ['4']]))->run('SELECT a FROM t', StatementKind::Read, new QueryOptions(maxRows: 3));

        self::assertSame([['1'], ['2'], ['3']], $result->rows);
        self::assertTrue($result->truncated);
    }

    public function testAWriteReportsTheRowsTheEngineWrote(): void
    {
        $result = $this->runner(new MockResponse('', ['response_headers' => ['X-ClickHouse-Summary: {"written_rows":"3"}']]))
            ->run('INSERT INTO t VALUES (1)', StatementKind::Write, new QueryOptions(QueryMode::ReadWrite));

        self::assertFalse($result->returnsRows());
        self::assertSame(3, $result->affectedRows);
    }

    public function testCompositeValuesBecomeJsonText(): void
    {
        $result = $this->runner(self::rows([[[1, 2]], [['k' => 'v']], [null], [true], [1.5]]))->run('SELECT a FROM t', StatementKind::Read, new QueryOptions());

        self::assertSame([['[1,2]'], ['{"k":"v"}'], [null], [true], [1.5]], $result->rows);
    }

    public function testHugeIntegersStayStrings(): void
    {
        $result = $this->runner(self::rows([['18446744073709551615']], 'UInt64'))->run('SELECT a FROM t', StatementKind::Read, new QueryOptions());

        self::assertSame([['18446744073709551615']], $result->rows);
    }

    public function testTheEnginesTimeoutBecomesATimeout(): void
    {
        $this->expectException(QueryTimeoutException::class);

        $this->runner(new MockResponse("Code: 159. DB::Exception: Timeout exceeded: elapsed 1.2 seconds, maximum: 1. (TIMEOUT_EXCEEDED)\n", ['http_code' => 408, 'response_headers' => ['X-ClickHouse-Exception-Code: 159']]))
            ->run('SELECT 1', StatementKind::Read, new QueryOptions());
    }

    public function testAnyOtherEngineErrorKeepsTheEnginesMessage(): void
    {
        try {
            $this->runner(new MockResponse("Code: 60. DB::Exception: Unknown table expression identifier 'nope'. (UNKNOWN_TABLE)\n", ['http_code' => 404, 'response_headers' => ['X-ClickHouse-Exception-Code: 60']]))
                ->run('SELECT * FROM nope', StatementKind::Read, new QueryOptions());
            self::fail('Expected a failure.');
        } catch (QueryFailedException $e) {
            self::assertStringContainsString('Unknown table expression', $e->getMessage());
            self::assertStringNotContainsString('Code: 60', $e->getMessage());
        }
    }

    public function testALostServerIsAFailureWithoutItsAddress(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['error' => 'refused by ch.example.test']));
        $runner = new ClickHouseQueryRunner(new ClickHouseHttp($client, new ConnectionParameters('ch.example.test', 8123, 'shop', 'reader', 'not-a-real-secret')));

        try {
            $runner->run('SELECT 1', StatementKind::Read, new QueryOptions());
            self::fail('Expected a failure.');
        } catch (QueryFailedException $e) {
            self::assertStringNotContainsString('ch.example.test', $e->getMessage());
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Driver\ClickHouse;

use App\Core\Connection\ConnectionParameters;
use App\Core\Diagnostics\Availability;
use App\Core\Diagnostics\DigestOrder;
use App\Core\Diagnostics\DigestQuery;
use App\Core\Diagnostics\Exception\DiagnosticsFailedException;
use App\Driver\ClickHouse\ClickHouseHttp;
use App\Driver\ClickHouse\ClickHouseStatementDiagnostics;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ClickHouseStatementDiagnosticsTest extends TestCase
{
    private string $lastUrl = '';
    private string $lastBody = '';

    private function diagnostics(MockResponse $response): ClickHouseStatementDiagnostics
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $body = $options['body'] ?? '';
            $this->lastUrl = $url;
            $this->lastBody = \is_string($body) ? $body : '';

            return $response;
        });

        return new ClickHouseStatementDiagnostics(new ClickHouseHttp($client, new ConnectionParameters('ch.example.test', 8123, 'shop', 'reader', 'not-a-real-secret')));
    }

    private static function failure(int $code): MockResponse
    {
        return new MockResponse(\sprintf("Code: %d. DB::Exception: refused. (NAME) (version 26.10.1.1 (official build))\n", $code), ['http_code' => 404, 'response_headers' => ['X-ClickHouse-Exception-Code: '.$code]]);
    }

    public function testAMissingQueryLogIsASourceSwitchedOff(): void
    {
        $diagnostics = $this->diagnostics(self::failure(60));

        $status = $diagnostics->status();

        self::assertSame(Availability::Disabled, $status->availability);
        self::assertSame('query_log_off', $status->reason);
        self::assertSame([], $diagnostics->top(new DigestQuery())->digests);
    }

    public function testARefusedAccessIsInsufficientPrivilege(): void
    {
        $status = $this->diagnostics(self::failure(497))->status();

        self::assertSame(Availability::PermissionDenied, $status->availability);
        self::assertSame('SELECT ON system.query_log', $status->privilege);
    }

    public function testAnyOtherFailureIsAnErrorNotAState(): void
    {
        $this->expectException(DiagnosticsFailedException::class);

        $this->diagnostics(self::failure(999))->status();
    }

    public function testReadsDigestsWithTheEngineEnforcingReadOnly(): void
    {
        $rows = [['SELECT count() FROM t WHERE a = ?', 'shop', '12', 3600.0, 300.0, '900', '5000000', '12', '2026-11-11T10:00:00Z', '2026-11-11T11:30:00Z']];
        $meta = array_map(static fn (string $n): array => ['name' => $n, 'type' => 'String'], ['text', 'db', 'calls', 'total_ms', 'mean_ms', 'max_ms', 'examined', 'returned', 'first_seen', 'last_seen']);
        $diagnostics = $this->diagnostics(new MockResponse(json_encode(['meta' => $meta, 'data' => $rows], \JSON_THROW_ON_ERROR)));

        $report = $diagnostics->top(new DigestQuery(DigestOrder::MeanTime, 7));

        self::assertSame(Availability::Available, $report->status->availability);
        self::assertCount(1, $report->digests);
        $digest = $report->digests[0];
        self::assertSame(12, $digest->calls);
        self::assertSame(3600.0, $digest->totalMs);
        self::assertSame(900.0, $digest->maxMs);
        self::assertSame(5000000, $digest->rowsExamined);
        self::assertSame(12, $digest->rowsReturned);
        self::assertSame('2026-11-11T10:00:00Z', $digest->firstSeen);
        self::assertStringContainsString('readonly=1', $this->lastUrl);
        self::assertStringContainsString('ORDER BY mean_ms DESC', $this->lastBody);
        self::assertStringContainsString('LIMIT 7', $this->lastBody);
    }
}

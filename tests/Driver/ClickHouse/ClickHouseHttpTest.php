<?php

declare(strict_types=1);

namespace App\Tests\Driver\ClickHouse;

use App\Core\Connection\ConnectionParameters;
use App\Driver\ClickHouse\ClickHouseException;
use App\Driver\ClickHouse\ClickHouseHttp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ClickHouseHttpTest extends TestCase
{
    private const SECRET = 'not-a-real-secret';

    /** @var list<array{method: string, url: string, options: array<mixed>}> */
    private array $requests = [];

    private function http(MockResponse $response, int $port = 8123): ClickHouseHttp
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return $response;
        });

        return new ClickHouseHttp($client, new ConnectionParameters('ch.example.test', $port, 'shop', 'reader', self::SECRET));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $info
     */
    private static function json(array $payload, array $info = []): MockResponse
    {
        return new MockResponse(json_encode($payload, \JSON_THROW_ON_ERROR), $info + ['response_headers' => ['X-ClickHouse-Summary: {"written_rows":"0"}']]);
    }

    /**
     * @return array<string, string>
     */
    private function query(): array
    {
        parse_str((string) parse_url($this->requests[0]['url'], \PHP_URL_QUERY), $query);
        $typed = [];
        foreach ($query as $name => $value) {
            $typed[(string) $name] = \is_string($value) ? $value : '';
        }

        return $typed;
    }

    public function testSendsTheStatementInTheBodyAndAsksForJson(): void
    {
        $result = $this->http(self::json(['meta' => [['name' => 'a', 'type' => 'UInt8']], 'data' => [[1], [2]], 'rows' => 2]))->query('SELECT a FROM t');

        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame('SELECT a FROM t', $this->requests[0]['options']['body']);
        self::assertSame('http://ch.example.test:8123/', explode('?', $this->requests[0]['url'])[0]);
        self::assertSame('JSONCompact', $this->query()['default_format']);
        self::assertSame('shop', $this->query()['database']);
        self::assertSame(['a'], $result->columns);
        self::assertSame([[1], [2]], $result->rows);
    }

    public function testRecordsPairEachColumnWithItsValue(): void
    {
        $result = $this->http(self::json(['meta' => [['name' => 'a', 'type' => 'UInt8'], ['name' => 'b', 'type' => 'String']], 'data' => [[1, 'x'], [2, 'y']]]))->query('SELECT a, b FROM t');

        self::assertSame([['a' => 1, 'b' => 'x'], ['a' => 2, 'b' => 'y']], $result->records());
    }

    public function testIntegersBeyondPhpAreKeptAsText(): void
    {
        $result = $this->http(new MockResponse('{"meta":[{"name":"a","type":"UInt64"}],"data":[[18446744073709551615],[7]]}'))->query('SELECT a FROM t');

        self::assertSame([['18446744073709551615'], [7]], $result->rows);
    }

    public function testCredentialsTravelInHeadersNeverInTheUrl(): void
    {
        $this->http(self::json(['meta' => [], 'data' => []]))->query('SELECT 1');

        self::assertStringNotContainsString(self::SECRET, $this->requests[0]['url']);
        self::assertStringNotContainsString('reader', $this->requests[0]['url']);
        $headers = $this->requests[0]['options']['headers'] ?? [];
        self::assertIsArray($headers);
        $headers = implode("\n", array_map(static fn (mixed $header): string => \is_string($header) ? $header : '', $headers));
        self::assertStringContainsString('X-ClickHouse-User: reader', $headers);
        self::assertStringContainsString('X-ClickHouse-Key: '.self::SECRET, $headers);
    }

    public function testSettingsAndBoundParametersAreQueryParameters(): void
    {
        $this->http(self::json(['meta' => [], 'data' => []]))->query('SELECT {x:String}', ['readonly' => 1, 'max_execution_time' => 2.5], ['x' => 'a b&c']);

        $query = $this->query();
        self::assertSame('1', $query['readonly']);
        self::assertSame('2.5', $query['max_execution_time']);
        self::assertSame('a b&c', $query['param_x']);
    }

    public function testSecurePortsUseTls(): void
    {
        $this->http(self::json(['meta' => [], 'data' => []]), 8443)->query('SELECT 1');

        self::assertStringStartsWith('https://ch.example.test:8443/', $this->requests[0]['url']);
    }

    public function testStatementsWithoutRowsReportWhatWasWritten(): void
    {
        $response = new MockResponse('', ['response_headers' => ['X-ClickHouse-Summary: {"read_rows":"0","written_rows":"12"}']]);

        $result = $this->http($response)->query('INSERT INTO t VALUES (1)');

        self::assertSame([], $result->columns);
        self::assertSame(12, $result->writtenRows);
    }

    public function testAnEngineErrorCarriesItsCodeAndACleanMessage(): void
    {
        $body = "Code: 62. DB::Exception: Syntax error: failed at position 8 ('FROM'). (SYNTAX_ERROR) (version 25.3.1.1 (official build))\n";

        try {
            $this->http(new MockResponse($body, ['http_code' => 400, 'response_headers' => ['X-ClickHouse-Exception-Code: 62']]))->query('SELECT FROM');
            self::fail('Expected an error.');
        } catch (ClickHouseException $e) {
            self::assertSame(62, $e->engineCode);
            self::assertSame("Syntax error: failed at position 8 ('FROM'). (SYNTAX_ERROR)", $e->getMessage());
        }
    }

    public function testAnUnreachableServerIsReportedWithoutItsAddress(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['error' => 'Connection refused to ch.example.test:8123']));
        $http = new ClickHouseHttp($client, new ConnectionParameters('ch.example.test', 8123, 'shop', 'reader', self::SECRET));

        try {
            $http->query('SELECT 1');
            self::fail('Expected an error.');
        } catch (ClickHouseException $e) {
            self::assertNull($e->engineCode);
            self::assertStringNotContainsString('ch.example.test', $e->getMessage());
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
        }
    }

    public function testAnAnswerThatIsNotJsonIsAnError(): void
    {
        $this->expectException(ClickHouseException::class);

        $this->http(new MockResponse('not json at all'))->query('SELECT 1');
    }

    public function testTheHttpTimeoutFollowsTheRequestedBound(): void
    {
        $this->http(self::json(['meta' => [], 'data' => []]))->query('SELECT 1', timeoutSeconds: 4.0);

        self::assertGreaterThan(4.0, $this->requests[0]['options']['timeout']);
        self::assertLessThan(15.0, $this->requests[0]['options']['timeout']);
    }
}

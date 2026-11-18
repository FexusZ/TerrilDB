<?php

declare(strict_types=1);

namespace App\Tests\Driver\ClickHouse;

use App\Core\Connection\ConnectionParameters;
use App\Core\Diagnostics\Availability;
use App\Core\Diagnostics\Exception\DiagnosticsFailedException;
use App\Core\Diagnostics\Replication\ReplicationRole;
use App\Driver\ClickHouse\ClickHouseHttp;
use App\Driver\ClickHouse\ClickHouseReplicationDiagnostics;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Réponses réelles de `system.replicas` (ClickHouse 26.10) : serveur sans table répliquée, puis serveur avec Keeper embarqué et
 * une table ReplicatedMergeTree.
 */
final class ClickHouseReplicationDiagnosticsTest extends TestCase
{
    /** @var list<string> */
    private array $bodies = [];

    private function diagnostics(MockResponse $response): ClickHouseReplicationDiagnostics
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $this->bodies[] = \is_string($options['body'] ?? null) ? $options['body'].' '.$url : $url;

            return $response;
        });

        return new ClickHouseReplicationDiagnostics(new ClickHouseHttp($client, new ConnectionParameters('ch.example.test', 8123, 'shop', 'reader', 'not-a-real-secret')));
    }

    private static function fixture(string $name): MockResponse
    {
        return new MockResponse((string) file_get_contents(__DIR__.'/../../Fixtures/replication/'.$name.'.json'));
    }

    private static function denied(): MockResponse
    {
        return new MockResponse("Code: 497. DB::Exception: reader: Not enough privileges. To execute this query, it's necessary to have the grant SELECT(database) ON system.replicas. (ACCESS_DENIED)\n", ['http_code' => 403, 'response_headers' => ['X-ClickHouse-Exception-Code: 497']]);
    }

    public function testAServerWithoutReplicatedTablesHasNoReplication(): void
    {
        $report = $this->diagnostics(self::fixture('ch-replicas-none'))->replication();

        self::assertSame(Availability::Available, $report->status->availability);
        self::assertSame(ReplicationRole::None, $report->role);
        self::assertSame([], $report->channels);
        self::assertSame(Availability::Available, $report->extras->tables?->status->availability);
        self::assertSame([], $report->extras->tables->tables);
        self::assertSame([], $report->alerts());
    }

    public function testAReplicatedTableIsReadAndItsPeersHaveNoFixedRole(): void
    {
        $report = $this->diagnostics(self::fixture('ch-replicas-one'))->replication();

        self::assertSame(ReplicationRole::Peer, $report->role);
        $table = $report->extras->tables?->tables[0];
        self::assertSame('d.rt', $table?->name());
        self::assertFalse($table->readonly);
        self::assertFalse($table->sessionExpired);
        self::assertSame(0, $table->absoluteDelaySeconds);
        self::assertSame(0, $table->queueSize);
        self::assertSame(0, $table->lostParts);
        self::assertSame([], $report->alerts());
    }

    public function testTheQueryIsFixedReadOnlyAndBounded(): void
    {
        $this->diagnostics(self::fixture('ch-replicas-none'))->replication();

        self::assertStringContainsString('FROM system.replicas', $this->bodies[0]);
        self::assertStringContainsString('LIMIT 201', $this->bodies[0]);
        self::assertStringContainsString('readonly=1', $this->bodies[0]);
        self::assertStringNotContainsString('zookeeper', strtolower(explode(' ', $this->bodies[0], 2)[0]), 'No ZooKeeper path or message is read.');
    }

    public function testBrokenTablesRaiseAlertsAndTheListIsBounded(): void
    {
        $data = [];
        for ($i = 0; $i < 201; ++$i) {
            $data[] = ['d', 't'.$i, 1, 1, '400', 150, 3, '2'];
        }
        $body = json_encode(['meta' => [['name' => 'database', 'type' => 'String'], ['name' => 'table', 'type' => 'String'], ['name' => 'is_readonly', 'type' => 'UInt8'], ['name' => 'is_session_expired', 'type' => 'UInt8'], ['name' => 'absolute_delay', 'type' => 'UInt64'], ['name' => 'queue_size', 'type' => 'UInt32'], ['name' => 'inserts_in_queue', 'type' => 'UInt32'], ['name' => 'lost_part_count', 'type' => 'UInt64']], 'data' => $data], \JSON_THROW_ON_ERROR);

        $report = $this->diagnostics(new MockResponse($body))->replication();

        $tables = $report->extras->tables;
        self::assertNotNull($tables);
        self::assertCount(200, $tables->tables);
        self::assertTrue($tables->truncated);
        self::assertSame(400, $tables->tables[0]->absoluteDelaySeconds);
        self::assertSame(2, $tables->tables[0]->lostParts);
        $codes = array_map(static fn ($a): string => $a->code->value, $report->alerts());
        self::assertContains('table_readonly', $codes);
        self::assertContains('table_lag_critical', $codes);
        self::assertContains('table_lost_parts', $codes);
    }

    public function testMissingPrivilegeIsAStateNotAnError(): void
    {
        $diagnostics = $this->diagnostics(self::denied());

        $report = $diagnostics->replication();

        self::assertSame(Availability::PermissionDenied, $report->status->availability);
        self::assertSame('SELECT ON system.replicas', $report->status->privilege);
        self::assertSame([], $report->alerts());
    }

    public function testStatusIsAvailableOrDenied(): void
    {
        self::assertSame(Availability::Available, $this->diagnostics(self::fixture('ch-replicas-none'))->status()->availability);
        self::assertSame(Availability::PermissionDenied, $this->diagnostics(self::denied())->status()->availability);
    }

    public function testAnotherFailureIsTheGenericDiagnosticsFailure(): void
    {
        $this->expectException(DiagnosticsFailedException::class);

        $this->diagnostics(new MockResponse("Code: 60. DB::Exception: Unknown table (UNKNOWN_TABLE)\n", ['http_code' => 404, 'response_headers' => ['X-ClickHouse-Exception-Code: 60']]))->replication();
    }
}

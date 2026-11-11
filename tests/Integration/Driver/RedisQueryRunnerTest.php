<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Guard\GuardReason;
use App\Core\Guard\RedisCommandGuard;
use App\Core\Guard\StatementKind;
use App\Core\Query\Exception\QueryFailedException;
use App\Core\Query\Exception\QueryRefusedException;
use App\Core\Query\Exception\QueryTimeoutException;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryOptions;
use App\Driver\Redis\RedisConnector;
use App\Driver\Redis\RedisQueryRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Predis\Client;

#[Group('integration')]
final class RedisQueryRunnerTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $this->client = RedisTarget::seeded();
    }

    protected function tearDown(): void
    {
        $this->client->flushdb();
    }

    private function runner(): RedisQueryRunner
    {
        return new RedisQueryRunner(static fn (float $timeout): Client => (new RedisConnector())->open(TargetDatabase::parameters(RedisTarget::ENV), $timeout));
    }

    private function read(string $command, int $maxRows = 1000): \App\Core\Query\QueryResult
    {
        return $this->runner()->run($command, StatementKind::Read, new QueryOptions(maxRows: $maxRows));
    }

    public function testAReplyBecomesAOneColumnTable(): void
    {
        $result = $this->read('get user:1');

        self::assertSame(['value'], $result->columns);
        self::assertSame([['Alice']], $result->rows);
        self::assertTrue($result->returnsRows());
    }

    public function testAMissingKeyIsANullValue(): void
    {
        self::assertSame([[null]], $this->read('get nope')->rows);
    }

    public function testNumbersStayNumbers(): void
    {
        self::assertSame([[2]], $this->read('exists user:1 user:2')->rows);
    }

    public function testAListReplyIsOneRowPerElement(): void
    {
        self::assertSame([['a'], ['b'], ['c']], $this->read('lrange queue 0 -1')->rows);
    }

    public function testNestedRepliesBecomeJsonText(): void
    {
        $result = $this->read('xrange events - +');

        self::assertSame([['["1-1",["kind","login","user","1"]]']], $result->rows);
    }

    public function testAHashComesAsFieldAndValue(): void
    {
        $result = $this->read('hgetall profile:1');

        self::assertSame(['field', 'value'], $result->columns);
        $rows = $result->rows;
        sort($rows);
        self::assertSame([['country', 'FR'], ['name', 'Alice']], $rows);
    }

    public function testInfoComesAsSectionKeyAndValue(): void
    {
        $result = $this->read('info server');

        self::assertSame(['section', 'key', 'value'], $result->columns);
        $keys = array_column($result->rows, 1);
        self::assertContains('redis_version', $keys);
        self::assertSame('Server', $result->rows[0][0]);
    }

    public function testCutsAtTheRowLimitAndFlagsIt(): void
    {
        $this->client->rpush('long', array_map(strval(...), range(1, 50)));

        $result = $this->read('lrange long 0 -1', 10);

        self::assertCount(10, $result->rows);
        self::assertTrue($result->truncated);
    }

    public function testReadOnlyModeReallyRefusesWritesEvenIfTheGuardWasSkipped(): void
    {
        try {
            $this->runner()->run('set user:1 hacked', StatementKind::Read, new QueryOptions(QueryMode::ReadOnly));
            self::fail('The write was run.');
        } catch (QueryRefusedException $e) {
            self::assertSame(GuardReason::NotReadOnly, $e->reason);
        }
        self::assertSame('Alice', $this->client->get('user:1'));
    }

    public function testReadOnlyModeRefusesAdministrationEvenIfTheGuardWasSkipped(): void
    {
        foreach (['flushdb', 'config get save', 'debug sleep 0'] as $command) {
            try {
                $this->runner()->run($command, StatementKind::Read, new QueryOptions(QueryMode::ReadOnly));
                self::fail('The command was run: '.$command);
            } catch (QueryRefusedException) {
                // attendu
            }
        }
        self::assertSame(9, $this->client->dbsize());
    }

    public function testAnUnknownCommandIsRefusedBeforeBeingSent(): void
    {
        $this->expectException(QueryRefusedException::class);

        $this->runner()->run('frobnicate k', StatementKind::Read, new QueryOptions(QueryMode::ReadOnly));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allowedReads(): iterable
    {
        foreach (RedisCommandGuard::readCommandNames() as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('allowedReads')]
    public function testTheServerDoesNotClassifyAnAllowedReadAsAWriteOrAdministration(string $name): void
    {
        $info = $this->client->executeRaw(['COMMAND', 'INFO', strtolower($name)]);

        self::assertIsArray($info);
        self::assertIsArray($info[0] ?? null, 'The server does not know "'.$name.'".');
        $flags = array_map(static fn (mixed $flag): string => strtolower(\is_scalar($flag) ? (string) $flag : ''), \is_array($info[0][2] ?? null) ? $info[0][2] : []);
        foreach (['write', 'admin', 'blocking', 'pubsub'] as $dangerous) {
            self::assertNotContains($dangerous, $flags, $name);
        }
    }

    public function testReadWriteModeWrites(): void
    {
        $options = new QueryOptions(QueryMode::ReadWrite);

        $set = $this->runner()->run('set note "hello world"', StatementKind::Write, $options);
        $deleted = $this->runner()->run('del note user:1', StatementKind::Write, $options);

        self::assertSame([['OK']], $set->rows);
        self::assertSame([[2]], $deleted->rows);
        self::assertSame(8, $this->client->dbsize());
    }

    public function testArgumentsTravelAsArgumentsNotAsText(): void
    {
        $this->runner()->run('set "a b" "line1\nline2 \"quoted\""', StatementKind::Write, new QueryOptions(QueryMode::ReadWrite));

        self::assertSame("line1\nline2 \"quoted\"", $this->client->get('a b'));
    }

    public function testAServerErrorKeepsItsMessageWithoutItsCode(): void
    {
        try {
            $this->runner()->run('lpush user:1 x', StatementKind::Write, new QueryOptions(QueryMode::ReadWrite));
            self::fail('Expected a failure.');
        } catch (QueryFailedException $e) {
            self::assertStringContainsString('wrong kind of value', $e->getMessage());
            self::assertStringNotContainsString('WRONGTYPE', $e->getMessage());
        }
    }

    public function testASlowCommandIsStoppedAtTheTimeout(): void
    {
        $started = microtime(true);

        try {
            $this->runner()->run('blpop no-such-list 5', StatementKind::Write, new QueryOptions(QueryMode::ReadWrite, timeoutMs: 400));
            self::fail('Expected a timeout.');
        } catch (QueryTimeoutException) {
            self::assertLessThan(3.0, microtime(true) - $started);
        }
    }

    public function testTheConnectionStillWorksForTheNextCommandAfterAnError(): void
    {
        try {
            $this->runner()->run('lpush user:1 x', StatementKind::Write, new QueryOptions(QueryMode::ReadWrite));
        } catch (QueryFailedException) {
            // attendu
        }

        self::assertSame([['Alice']], $this->read('get user:1')->rows);
    }

    public function testMeasuringIsNotSupported(): void
    {
        $this->expectException(\App\Core\Driver\Exception\UnsupportedOperationException::class);
        $this->runner()->run('GET nothing', StatementKind::Read, new QueryOptions(QueryMode::ReadOnly, 30_000, 10, measure: true));
    }
}

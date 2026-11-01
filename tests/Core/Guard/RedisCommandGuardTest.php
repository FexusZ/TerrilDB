<?php

declare(strict_types=1);

namespace App\Tests\Core\Guard;

use App\Core\Guard\Decision;
use App\Core\Guard\GuardReason;
use App\Core\Guard\RedisCommandGuard;
use App\Core\Guard\StatementKind;
use App\Core\Query\QueryMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedisCommandGuardTest extends TestCase
{
    private function guard(): RedisCommandGuard
    {
        return new RedisCommandGuard();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reads(): iterable
    {
        foreach ([
            'get k', 'GET k', 'mget a b', 'strlen k', 'getrange k 0 10', 'exists a b', 'type k', 'ttl k', 'pttl k', 'dbsize',
            'scan 0 match user:* count 100', 'randomkey', 'object encoding k', 'memory usage k', 'hgetall h', 'hget h f', 'hlen h',
            'hscan h 0', 'lrange l 0 -1', 'llen l', 'lindex l 0', 'smembers s', 'scard s', 'sismember s x', 'sscan s 0',
            'zrange z 0 -1 withscores', 'zcard z', 'zscore z m', 'zscan z 0', 'xrange s - +', 'xlen s', 'xinfo stream s',
            'xread count 2 streams s 0', 'getbit k 0', 'bitcount k', 'geopos g m', 'ping', 'echo hi', 'info', 'info memory', 'time',
            'sort_ro l', 'command count', 'command info get',
        ] as $command) {
            yield $command => [$command];
        }
    }

    #[DataProvider('reads')]
    public function testReadCommandsPassInReadOnlyMode(string $command): void
    {
        $verdict = $this->guard()->check($command, QueryMode::ReadOnly);

        self::assertSame(Decision::Allowed, $verdict->decision, $command);
        self::assertSame(StatementKind::Read, $verdict->kind, $command);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function writes(): iterable
    {
        foreach ([
            'set k v', 'mset a 1 b 2', 'setex k 10 v', 'append k v', 'incr k', 'decrby k 2', 'getdel k', 'getex k ex 10', 'del k', 'unlink k',
            'expire k 10', 'persist k', 'rename a b', 'copy a b', 'hset h f v', 'hdel h f', 'hincrby h f 1', 'lpush l a', 'rpush l a',
            'lpop l', 'lset l 0 x', 'lrem l 0 x', 'ltrim l 0 10', 'sadd s a', 'srem s a', 'spop s', 'zadd z 1 a', 'zrem z a', 'zincrby z 1 a',
            'xadd s * f v', 'xdel s 1-0', 'xtrim s maxlen 10', 'pfadd h a', 'setbit k 1 1', 'geoadd g 1 2 m',
        ] as $command) {
            yield $command => [$command];
        }
    }

    #[DataProvider('writes')]
    public function testWritesAreRefusedInReadOnlyMode(string $command): void
    {
        $verdict = $this->guard()->check($command, QueryMode::ReadOnly);

        self::assertSame(Decision::Refused, $verdict->decision, $command);
        self::assertSame(GuardReason::NotReadOnly, $verdict->reason, $command);
    }

    #[DataProvider('writes')]
    public function testWritesPassInReadWriteMode(string $command): void
    {
        $verdict = $this->guard()->check($command, QueryMode::ReadWrite);

        self::assertSame(Decision::Allowed, $verdict->decision, $command);
        self::assertSame(StatementKind::Write, $verdict->kind, $command);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function forbidden(): iterable
    {
        foreach ([
            'keys *', 'monitor', 'subscribe c', 'psubscribe c*', 'blpop l 0', 'brpop l 0', 'brpoplpush a b 0', 'xread block 0 streams s $',
            'xreadgroup group g c block 0 streams s >', 'debug sleep 1', 'eval "return 1" 0', 'evalsha abc 0', 'eval_ro "return 1" 0',
            'function list', 'script flush', 'module list', 'config get *', 'config set save ""', 'acl list', 'acl whoami', 'client list',
            'client kill id 3', 'shutdown', 'shutdown nosave', 'replicaof no one', 'slaveof no one', 'migrate h 6379 k 0 5000',
            'save', 'bgsave', 'bgrewriteaof', 'flushall', 'flushall async', 'auth secret', 'hello 3', 'failover', 'cluster nodes',
            'sort l store dest', 'wait 1 100', 'slowlog get', 'latency doctor', 'psync x y', 'sync', 'object help x', 'lolwut',
        ] as $command) {
            yield $command => [$command];
        }
    }

    #[DataProvider('forbidden')]
    public function testDangerousCommandsAreRefusedInEveryMode(string $command): void
    {
        foreach ([QueryMode::ReadOnly, QueryMode::ReadWrite] as $mode) {
            $verdict = $this->guard()->check($command, $mode);

            self::assertSame(Decision::Refused, $verdict->decision, $command);
            self::assertContains($verdict->reason, [GuardReason::ForbiddenConstruct, GuardReason::UnsupportedStatement, GuardReason::NotReadOnly], $command);
        }
    }

    public function testKeysAndBlockingReadsAreForbiddenNotJustUnknown(): void
    {
        foreach (['keys *', 'blpop l 0', 'xread block 0 streams s $', 'config get requirepass', 'flushall'] as $command) {
            self::assertSame(GuardReason::ForbiddenConstruct, $this->guard()->check($command, QueryMode::ReadOnly)->reason, $command);
        }
    }

    public function testSessionAndTransactionControlIsUnsupported(): void
    {
        foreach (['select 3', 'multi', 'exec', 'discard', 'watch k', 'unwatch', 'quit', 'reset', 'move k 3'] as $command) {
            self::assertSame(GuardReason::UnsupportedStatement, $this->guard()->check($command, QueryMode::ReadWrite)->reason, $command);
        }
    }

    public function testAnUnknownCommandIsUnsupported(): void
    {
        self::assertSame(GuardReason::UnsupportedStatement, $this->guard()->check('frobnicate k', QueryMode::ReadWrite)->reason);
    }

    public function testFlushingTheDatabaseAsksForConfirmation(): void
    {
        $verdict = $this->guard()->check('flushdb', QueryMode::ReadWrite);

        self::assertSame(Decision::NeedsConfirmation, $verdict->decision);
        self::assertSame(GuardReason::DestructiveStatement, $verdict->reason);
        self::assertSame(Decision::NeedsConfirmation, $this->guard()->check('FLUSHDB ASYNC', QueryMode::ReadWrite)->decision);
        self::assertSame(GuardReason::NotReadOnly, $this->guard()->check('flushdb', QueryMode::ReadOnly)->reason);
    }

    public function testOnlyOneCommandAtATime(): void
    {
        self::assertSame(GuardReason::MultipleStatements, $this->guard()->check("get a\nflushdb", QueryMode::ReadOnly)->reason);
    }

    public function testEmptyOrUnreadableTextIsRefused(): void
    {
        self::assertSame(GuardReason::EmptyStatement, $this->guard()->check('  ', QueryMode::ReadOnly)->reason);
        self::assertSame(GuardReason::IncompleteSql, $this->guard()->check('get "abc', QueryMode::ReadOnly)->reason);
    }

    public function testAbsurdlyLongTextIsRefused(): void
    {
        self::assertSame(GuardReason::TooLong, $this->guard()->check('get '.str_repeat('a', 100_001), QueryMode::ReadOnly)->reason);
    }

    public function testTheCommandNameIsReadAfterUnquoting(): void
    {
        self::assertSame(GuardReason::ForbiddenConstruct, $this->guard()->check('"keys" "*"', QueryMode::ReadOnly)->reason);
        self::assertSame(Decision::Allowed, $this->guard()->check('"get" "a b"', QueryMode::ReadOnly)->decision);
    }

    public function testSubcommandsDecideForContainerCommands(): void
    {
        self::assertSame(Decision::Allowed, $this->guard()->check('object freq k', QueryMode::ReadOnly)->decision);
        self::assertSame(GuardReason::UnsupportedStatement, $this->guard()->check('object', QueryMode::ReadOnly)->reason);
        self::assertSame(GuardReason::ForbiddenConstruct, $this->guard()->check('memory purge', QueryMode::ReadOnly)->reason);
        self::assertSame(Decision::Allowed, $this->guard()->check('xinfo groups s', QueryMode::ReadOnly)->decision);
    }
}

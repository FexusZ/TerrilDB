<?php

declare(strict_types=1);

namespace App\Tests\Core\Diagnostics\Replication;

use App\Core\Diagnostics\Replication\ReplicationError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReplicationErrorTest extends TestCase
{
    public function testNoErrorWhenTheEngineReportsNone(): void
    {
        self::assertNull(ReplicationError::from(0, ''));
        self::assertNull(ReplicationError::from(null, null));
        self::assertNull(ReplicationError::from(0, null));
    }

    public function testKeepsTheCodeAndTheExplanation(): void
    {
        $error = ReplicationError::from(1062, "Worker 1 failed executing transaction 'ANONYMOUS' at source log mysql-bin.000012, end_log_pos 4711; Could not execute Write_rows event on table shop.orders; Duplicate entry '42' for key 'orders.PRIMARY', Error_code: 1062");

        self::assertNotNull($error);
        self::assertSame(1062, $error->code);
        self::assertStringContainsString('mysql-bin.000012', $error->message);
        self::assertStringContainsString('shop.orders', $error->message);
        self::assertStringContainsString('Duplicate entry', $error->message);
    }

    #[DataProvider('secrets')]
    public function testNeverCarriesAHostAnAddressOrAPassword(string $raw, string $leak): void
    {
        $error = ReplicationError::from(2003, $raw);

        self::assertNotNull($error);
        self::assertStringNotContainsString($leak, $error->message);
        self::assertStringContainsString('…', $error->message);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function secrets(): iterable
    {
        yield 'user and host' => ["error connecting to source 'repl@db1.corp.example:3306'. This was attempt 3/10", 'corp.example'];
        yield 'quoted account' => ["Access denied for user 'repl'@'10.20.30.40' (using password: YES)", '10.20.30.40'];
        yield 'address and port' => ['Can\'t connect to MySQL server on 192.168.7.9:3306 (111)', '192.168.7.9'];
        yield 'ipv6' => ["Can't connect to server on 'fd00:1234::5' (111)", 'fd00:1234::5'];
        yield 'long host name' => ['Unknown host replica-1.internal.example.net', 'internal.example.net'];
        yield 'host with port' => ['Lost connection to source primary-eu:3306 during query', 'primary-eu'];
        yield 'wildcard host' => ["Access denied for user 'repl'@'%' (using password: YES)", 'repl'];
        yield 'two-label host' => ["Unknown MySQL server host 'db.corp' (-2)", 'db.corp'];
        yield 'single-label host' => ["Can't connect to MySQL server on 'primary' (111)", 'primary'];
        yield 'postgres user' => ['FATAL: password authentication failed for user "replicator"', 'replicator'];
        yield 'postgres host' => ['connection to server at "db-primary.internal" (10.1.2.3), port 5432 failed', 'db-primary'];
        yield 'url with credentials' => ['Error connecting to https://repl:s3cret@primary.example.com/path', 'repl:'];
        yield 'url host' => ['Error connecting to https://repl:s3cret@primary.example.com/path', 'primary'];
        yield 'password with a space' => ["SOURCE_PASSWORD='pa ss' rejected", "ss'"];
        yield 'source host setting' => ["MASTER_HOST='primary.lan' unreachable", 'primary.lan'];
        yield 'source user setting' => ["SOURCE_USER='repl_user' refused", 'repl_user'];
        yield 'conninfo' => ['host=db.corp user=repl dbname=x', 'db.corp'];
        yield 'conninfo user' => ['host=db.corp user=repl dbname=x', 'repl'];
        yield 'token' => ['token=abcd1234efgh used for source', 'abcd1234efgh'];
        yield 'bearer' => ['Authorization: Bearer abcd1234efgh.zz refused', 'abcd1234efgh'];
        yield 'password setting' => ['CHANGE REPLICATION SOURCE failed: SOURCE_PASSWORD=hunter2 rejected', 'hunter2'];
    }

    public function testTheTextIsBoundedAndWithoutControlCharacters(): void
    {
        $error = ReplicationError::from(1, "line one\nline\ttwo\x00 ".str_repeat('x', 2000));

        self::assertNotNull($error);
        self::assertLessThanOrEqual(501, mb_strlen($error->message));
        self::assertDoesNotMatchRegularExpression('/[\x00-\x1f]/', $error->message);
    }

    public function testATextWithoutCodeIsStillAnError(): void
    {
        $error = ReplicationError::from(null, 'something broke');

        self::assertNotNull($error);
        self::assertNull($error->code);
    }
}

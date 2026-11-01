<?php

declare(strict_types=1);

namespace App\Tests\Core\Guard;

use App\Core\Connection\Engine;
use App\Core\Guard\Decision;
use App\Core\Guard\GuardReason;
use App\Core\Guard\GuardVerdict;
use App\Core\Guard\StatementGuard;
use App\Core\Guard\StatementKind;
use App\Core\Query\QueryMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatementGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{Engine}>
     */
    public static function engines(): iterable
    {
        yield 'MySQL' => [Engine::MySql];
        yield 'MariaDB' => [Engine::MariaDb];
        yield 'PostgreSQL' => [Engine::PostgreSql];
        yield 'ClickHouse' => [Engine::ClickHouse];
    }

    private function check(string $sql, Engine $engine, QueryMode $mode): GuardVerdict
    {
        return (new StatementGuard())->check($sql, $engine, $mode);
    }

    private function assertAllowed(string $sql, Engine $engine, QueryMode $mode, ?StatementKind $kind = null): void
    {
        $verdict = $this->check($sql, $engine, $mode);
        self::assertSame(Decision::Allowed, $verdict->decision, $sql.' -> '.($verdict->reason->name ?? ''));
        if (null !== $kind) {
            self::assertSame($kind, $verdict->kind, $sql);
        }
    }

    private function assertRefused(string $sql, Engine $engine, QueryMode $mode, GuardReason $reason): void
    {
        $verdict = $this->check($sql, $engine, $mode);
        self::assertSame(Decision::Refused, $verdict->decision, $sql);
        self::assertSame($reason, $verdict->reason, $sql);
    }

    private function assertNeedsConfirmation(string $sql, Engine $engine, GuardReason $reason): void
    {
        $verdict = $this->check($sql, $engine, QueryMode::ReadWrite);
        self::assertSame(Decision::NeedsConfirmation, $verdict->decision, $sql);
        self::assertSame($reason, $verdict->reason, $sql);
    }

    // ---- Lecture seule : ce qui passe

    #[DataProvider('engines')]
    public function testReadStatementsPassInReadOnlyMode(Engine $engine): void
    {
        foreach ([
            'select * from orders',
            "  SELECT 1;\n",
            '-- note'."\n".'select a from t where b = 1',
            'values (1), (2)',
            'with c as (select 1 as a) select a from c',
            "select 'a; drop table t', 2 /* ; delete from t */",
            'select updated_at, deleted_at, into_x from t',
        ] as $sql) {
            $this->assertAllowed($sql, $engine, QueryMode::ReadOnly, StatementKind::Read);
        }
    }

    #[DataProvider('engines')]
    public function testInspectionStatementsPassInReadOnlyMode(Engine $engine): void
    {
        foreach (['show tables', 'describe orders', 'desc orders', 'explain select * from orders'] as $sql) {
            $this->assertAllowed($sql, $engine, QueryMode::ReadOnly, StatementKind::Inspect);
        }
    }

    public function testPostgreSqlTableStatementIsARead(): void
    {
        $this->assertAllowed('table orders', Engine::PostgreSql, QueryMode::ReadOnly, StatementKind::Read);
    }

    // ---- Lecture seule : ce qui est refusé

    #[DataProvider('engines')]
    public function testWritesAndSchemaChangesAreRefusedInReadOnlyMode(Engine $engine): void
    {
        foreach ([
            'insert into t values (1)',
            'update t set a = 1',
            'delete from t',
            'replace into t values (1)',
            'drop table t',
            'truncate t',
            'alter table t add c int',
            'create table t (a int)',
            'grant all on t to u',
            'with d as (delete from t returning *) select * from d',
            'explain analyze delete from t',
            'call do_things()',
        ] as $sql) {
            $this->assertRefused($sql, $engine, QueryMode::ReadOnly, GuardReason::NotReadOnly);
        }
    }

    #[DataProvider('engines')]
    public function testSessionAndTransactionControlIsRefusedInEveryMode(Engine $engine): void
    {
        foreach ([
            'set transaction read write',
            'set session characteristics as transaction read write',
            'begin',
            'start transaction',
            'commit',
            'rollback',
            'use other_db',
            'lock tables t write',
            'prepare p from \'select 1\'',
            'savepoint s',
        ] as $sql) {
            $this->assertRefused($sql, $engine, QueryMode::ReadOnly, GuardReason::UnsupportedStatement);
            $this->assertRefused($sql, $engine, QueryMode::ReadWrite, GuardReason::UnsupportedStatement);
        }
    }

    #[DataProvider('engines')]
    public function testOnlyOneStatementAtATime(Engine $engine): void
    {
        foreach ([QueryMode::ReadOnly, QueryMode::ReadWrite] as $mode) {
            $this->assertRefused('select 1; select 2', $engine, $mode, GuardReason::MultipleStatements);
            $this->assertRefused('select 1; drop table t', $engine, $mode, GuardReason::MultipleStatements);
        }
    }

    #[DataProvider('engines')]
    public function testEmptyOrUnreadableTextIsRefused(Engine $engine): void
    {
        $this->assertRefused('', $engine, QueryMode::ReadOnly, GuardReason::EmptyStatement);
        $this->assertRefused("  -- just a comment\n ; ", $engine, QueryMode::ReadOnly, GuardReason::EmptyStatement);
        $this->assertRefused("select 'abc", $engine, QueryMode::ReadOnly, GuardReason::IncompleteSql);
        $this->assertRefused('select 1 /* abc', $engine, QueryMode::ReadWrite, GuardReason::IncompleteSql);
    }

    public function testHiddenSecondStatementsAreSeenThroughEngineSpecificSyntax(): void
    {
        foreach ([Engine::MySql, Engine::MariaDb] as $engine) {
            $this->assertRefused('select 1 /*! ; drop table x */', $engine, QueryMode::ReadOnly, GuardReason::MultipleStatements);
            $this->assertRefused('select 1 /*!50000 ; drop table x */', $engine, QueryMode::ReadOnly, GuardReason::MultipleStatements);
            $this->assertRefused('select 1--1; drop table x', $engine, QueryMode::ReadOnly, GuardReason::MultipleStatements);
            $this->assertRefused("select 'a\\'; drop table x; --'; drop table y", $engine, QueryMode::ReadOnly, GuardReason::MultipleStatements);
        }
        $this->assertRefused("select 'a\\'; drop table x; --", Engine::PostgreSql, QueryMode::ReadOnly, GuardReason::MultipleStatements);
        $this->assertRefused("select 1 where 'a\\' = 'a'; drop table x", Engine::PostgreSql, QueryMode::ReadOnly, GuardReason::MultipleStatements);
    }

    public function testClickHouseHiddenSecondStatementsAreSeenThroughItsSyntax(): void
    {
        // « -- » ouvre un commentaire sans espace : tout le reste est du commentaire, comme pour le moteur.
        $this->assertAllowed('select 1--1; drop table x', Engine::ClickHouse, QueryMode::ReadOnly, StatementKind::Read);
        $this->assertRefused("select 'a\\'; drop table x; --'; drop table y", Engine::ClickHouse, QueryMode::ReadOnly, GuardReason::MultipleStatements);
        $this->assertRefused('select 1 # x'."\n".'; select 2', Engine::ClickHouse, QueryMode::ReadOnly, GuardReason::MultipleStatements);
    }

    public function testClickHouseTableFunctionsThatReachOutsideAreRefused(): void
    {
        foreach ([
            "select * from file('/etc/passwd', 'CSV')",
            "select * from url('http://example.test/x', 'CSV')",
            "select * from s3('http://example.test/x')",
            "select * from remote('other', db.t)",
            "select * from mysql('h:3306', 'db', 't', 'u', 'p')",
            "select * from postgresql('h:5432', 'db', 't', 'u', 'p')",
            "select * from executable('cmd', 'CSV', 'a String')",
            "select * from hdfs('hdfs://x/y', 'CSV')",
            "select * from FILE ('x', 'CSV')",
            "select * from clusterAllReplicas('c', system.one)",
            "select * from cluster('c', system.one)",
            "select * from sqlite('/x', 't')",
            "select * from odbc('x', 'db', 't')",
        ] as $sql) {
            $this->assertRefused($sql, Engine::ClickHouse, QueryMode::ReadOnly, GuardReason::ForbiddenConstruct);
            $this->assertRefused($sql, Engine::ClickHouse, QueryMode::ReadWrite, GuardReason::ForbiddenConstruct);
        }
    }

    public function testClickHouseColumnsNamedLikeTableFunctionsAreFine(): void
    {
        $this->assertAllowed('select file, url, remote from t', Engine::ClickHouse, QueryMode::ReadOnly, StatementKind::Read);
    }

    public function testClickHouseOutputFormatIsRefusedBecauseTheRunnerFixesIt(): void
    {
        $this->assertRefused('select 1 format CSV', Engine::ClickHouse, QueryMode::ReadOnly, GuardReason::ForbiddenConstruct);
        $this->assertAllowed("select format('{}', 1)", Engine::ClickHouse, QueryMode::ReadOnly, StatementKind::Read);
        $this->assertAllowed('select "format" from t', Engine::ClickHouse, QueryMode::ReadOnly, StatementKind::Read);
    }

    public function testClickHouseExistsIsAnInspection(): void
    {
        $this->assertAllowed('exists table t', Engine::ClickHouse, QueryMode::ReadOnly, StatementKind::Inspect);
    }

    public function testClickHouseDetachingNeedsConfirmation(): void
    {
        $this->assertNeedsConfirmation('detach table t', Engine::ClickHouse, GuardReason::DestructiveStatement);
        $this->assertNeedsConfirmation('exchange tables a and b', Engine::ClickHouse, GuardReason::DestructiveStatement);
    }

    public function testClickHouseServerControlIsRefused(): void
    {
        foreach (['system flush logs', 'kill query where 1', 'set max_threads = 1'] as $sql) {
            $this->assertRefused($sql, Engine::ClickHouse, QueryMode::ReadWrite, GuardReason::UnsupportedStatement);
        }
    }

    #[DataProvider('engines')]
    public function testConstructsThatReachOutsideTheTransactionAreRefused(Engine $engine): void
    {
        foreach ([
            "select * from t into outfile '/tmp/x'",
            "select * from t into dumpfile '/tmp/x'",
            'select * from t for update',
            'select * from t for share',
            'select * from t lock in share mode',
            'select * into backup from t',
            "select load_file('/etc/passwd')",
            "select pg_read_file('/etc/passwd')",
            "select pg_ls_dir('/')",
            "select lo_import('/etc/passwd')",
            "select * from dblink('host=x', 'select 1') as t(a int)",
            'select pg_terminate_backend(1)',
            "select set_config('x', 'y', false)",
        ] as $sql) {
            $this->assertRefused($sql, $engine, QueryMode::ReadOnly, GuardReason::ForbiddenConstruct);
        }
    }

    // ---- Lecture-écriture

    #[DataProvider('engines')]
    public function testReadsAndPlainWritesPassInReadWriteMode(Engine $engine): void
    {
        $this->assertAllowed('select 1', $engine, QueryMode::ReadWrite, StatementKind::Read);
        $this->assertAllowed('insert into t values (1)', $engine, QueryMode::ReadWrite, StatementKind::Write);
        $this->assertAllowed('update t set a = 1 where id = 3', $engine, QueryMode::ReadWrite, StatementKind::Write);
        $this->assertAllowed('delete from t where id = 3', $engine, QueryMode::ReadWrite, StatementKind::Write);
        $this->assertAllowed('create table t (a int)', $engine, QueryMode::ReadWrite, StatementKind::Schema);
    }

    #[DataProvider('engines')]
    public function testUpdatesAndDeletesWithoutWhereNeedConfirmation(Engine $engine): void
    {
        $this->assertNeedsConfirmation('update t set a = 1', $engine, GuardReason::DestructiveWithoutWhere);
        $this->assertNeedsConfirmation('delete from t', $engine, GuardReason::DestructiveWithoutWhere);
        $this->assertNeedsConfirmation('update t set a = (select b from u where u.id = 1)', $engine, GuardReason::DestructiveWithoutWhere);
        $this->assertNeedsConfirmation('explain analyze delete from t', $engine, GuardReason::DestructiveWithoutWhere);
    }

    #[DataProvider('engines')]
    public function testDestructiveSchemaChangesNeedConfirmation(Engine $engine): void
    {
        foreach (['drop table t', 'truncate table t', 'alter table t drop column a', 'grant all on t to u', 'revoke all on t from u', 'drop database d'] as $sql) {
            $this->assertNeedsConfirmation($sql, $engine, GuardReason::DestructiveStatement);
        }
    }

    #[DataProvider('engines')]
    public function testOpaqueRoutinesNeedConfirmation(Engine $engine): void
    {
        $this->assertNeedsConfirmation('call do_things()', $engine, GuardReason::DestructiveStatement);
    }

    #[DataProvider('engines')]
    public function testFileAccessIsRefusedEvenInReadWriteMode(Engine $engine): void
    {
        $this->assertRefused("select * from t into outfile '/tmp/x'", $engine, QueryMode::ReadWrite, GuardReason::ForbiddenConstruct);
        $this->assertRefused("load data infile '/tmp/x' into table t", $engine, QueryMode::ReadWrite, GuardReason::ForbiddenConstruct);
        $this->assertRefused("copy t to program 'cat'", $engine, QueryMode::ReadWrite, GuardReason::ForbiddenConstruct);
    }

    #[DataProvider('engines')]
    public function testAbsurdlyLongTextIsRefusedBeforeBeingAnalyzed(Engine $engine): void
    {
        $this->assertRefused('select '.str_repeat('1,', 100_000).'1', $engine, QueryMode::ReadOnly, GuardReason::TooLong);
        $this->assertAllowed('select '.str_repeat('1,', 1000).'1', $engine, QueryMode::ReadOnly);
    }

    public function testRedisCommandsGoThroughTheCommandGuardNotTheSqlOne(): void
    {
        $this->assertAllowed('get user:1', Engine::Redis, QueryMode::ReadOnly, StatementKind::Read);
        $this->assertRefused('set user:1 x', Engine::Redis, QueryMode::ReadOnly, GuardReason::NotReadOnly);
        $this->assertRefused('select * from t', Engine::Redis, QueryMode::ReadOnly, GuardReason::UnsupportedStatement);
        $this->assertAllowed('set user:1 x', Engine::Redis, QueryMode::ReadWrite, StatementKind::Write);
    }

    public function testTheVerdictCarriesNoReasonWhenAllowed(): void
    {
        $verdict = $this->check('select 1', Engine::PostgreSql, QueryMode::ReadOnly);

        self::assertNull($verdict->reason);
        self::assertTrue($verdict->allowsExecution());
        self::assertFalse($this->check('drop table t', Engine::PostgreSql, QueryMode::ReadOnly)->allowsExecution());
        self::assertFalse($this->check('drop table t', Engine::PostgreSql, QueryMode::ReadWrite)->allowsExecution());
    }
}

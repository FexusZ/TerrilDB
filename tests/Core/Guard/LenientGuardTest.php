<?php

declare(strict_types=1);

namespace App\Tests\Core\Guard;

use App\Core\Connection\Engine;
use App\Core\Guard\Decision;
use App\Core\Guard\GuardReason;
use App\Core\Guard\StatementGuard;
use App\Core\Query\QueryMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La console du site (une personne connectée, comme un terminal SQL) ne perd aucun outil : ce que le garde REFUSE pour les jetons d'API et
 * le MCP (fichiers du serveur, verrous durables, état du serveur…) y devient une confirmation « attention ». Le moteur et les droits du
 * compte restent les remparts ; rien d'autre n'est assoupli (une seule instruction, le mode, les droits d'écriture).
 */
final class LenientGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{string, Engine}>
     */
    public static function formerlyForbidden(): iterable
    {
        foreach ([
            "SELECT LOAD_FILE('/etc/hostname')", "SELECT * FROM t INTO OUTFILE '/tmp/x'", "SELECT 1 INTO DUMPFILE '/tmp/x'", "LOAD DATA INFILE '/tmp/x' INTO TABLE t",
            "SELECT GET_LOCK('a', 1)", 'SELECT /*+ MAX_EXECUTION_TIME(1) */ 1', 'SELECT * FROM t FOR UPDATE',
        ] as $sql) {
            yield 'MySQL '.$sql => [$sql, Engine::MySql];
        }
        foreach ([
            "SELECT pg_read_file('x')", 'SELECT pg_advisory_lock(1)', 'SELECT pg_switch_wal()', "COPY t TO '/tmp/x'", "SELECT pg_notify('a', 'b')", "SELECT * FROM dblink('x', 'select 1') AS t(a int)",
        ] as $sql) {
            yield 'PostgreSQL '.$sql => [$sql, Engine::PostgreSql];
        }
        foreach (["SELECT * FROM file('x')", "SELECT * FROM url('http://x', CSV)"] as $sql) {
            yield 'ClickHouse '.$sql => [$sql, Engine::ClickHouse];
        }
    }

    #[DataProvider('formerlyForbidden')]
    public function testAForbiddenConstructBecomesAConfirmationInLenientMode(string $sql, Engine $engine): void
    {
        $converted = 0;
        foreach ([QueryMode::ReadOnly, QueryMode::ReadWrite] as $mode) {
            $strict = (new StatementGuard())->check($sql, $engine, $mode);
            $lenient = (new StatementGuard())->check($sql, $engine, $mode, lenient: true);

            if (Decision::Refused !== $strict->decision || GuardReason::ForbiddenConstruct !== $strict->reason) {
                self::assertEquals($strict, $lenient, 'ce que le garde ne refusait pas pour cette raison ne change pas : '.$mode->name.' '.$sql);

                continue;
            }
            if (QueryMode::ReadOnly === $mode && \in_array($lenient->reason, [GuardReason::NotReadOnly], true)) {
                continue; // une écriture (LOAD, COPY) reste refusée EN LECTURE SEULE : c'est le mode, pas la construction.
            }
            self::assertSame(Decision::NeedsConfirmation, $lenient->decision, $mode->name.' '.$sql);
            self::assertSame(GuardReason::ForbiddenConstruct, $lenient->reason, $sql);
            self::assertNotNull($lenient->kind, $sql);
            ++$converted;
        }
        self::assertGreaterThan(0, $converted, $sql);
    }

    public function testTheStrictDefaultIsUnchanged(): void
    {
        $verdict = (new StatementGuard())->check("SELECT LOAD_FILE('/etc/hostname')", Engine::MySql, QueryMode::ReadOnly);

        self::assertSame(Decision::Refused, $verdict->decision);
    }

    /**
     * @return iterable<string, array{string, QueryMode, GuardReason}>
     */
    public static function neverRelaxed(): iterable
    {
        yield 'plusieurs instructions' => ['SELECT 1; SELECT 2', QueryMode::ReadOnly, GuardReason::MultipleStatements];
        yield 'écriture en lecture seule' => ['DROP TABLE t', QueryMode::ReadOnly, GuardReason::NotReadOnly];
        yield 'instruction incomplète' => ["SELECT 'abc", QueryMode::ReadOnly, GuardReason::IncompleteSql];
        yield 'verbe inconnu' => ['FROBNICATE t', QueryMode::ReadWrite, GuardReason::UnsupportedStatement];
    }

    #[DataProvider('neverRelaxed')]
    public function testTheRestStaysRefusedInLenientMode(string $sql, QueryMode $mode, GuardReason $reason): void
    {
        $verdict = (new StatementGuard())->check($sql, Engine::MySql, $mode, lenient: true);

        self::assertSame(Decision::Refused, $verdict->decision);
        self::assertSame($reason, $verdict->reason);
    }

    public function testAWriteNotPermittedByTheRightsStaysRefusedEvenWhenLenient(): void
    {
        $verdict = (new StatementGuard())->check("SELECT LOAD_FILE('x')", Engine::MySql, QueryMode::ReadWrite, new \App\Core\Query\WritePermissions(false, false), lenient: true);

        self::assertSame(Decision::NeedsConfirmation, $verdict->decision, 'une lecture n\'a pas besoin de droit d\'écriture');
        $write = (new StatementGuard())->check('DELETE FROM t WHERE id = 1', Engine::MySql, QueryMode::ReadWrite, new \App\Core\Query\WritePermissions(false, false), lenient: true);
        self::assertSame(GuardReason::WriteNotPermitted, $write->reason);
    }

    public function testAMigrationRevertIsAStructureChangeNeedingConfirmation(): void
    {
        $verdict = (new StatementGuard())->check("REVERT VITESS_MIGRATION 'x'", Engine::MySql, QueryMode::ReadWrite);

        self::assertSame(Decision::NeedsConfirmation, $verdict->decision);
        self::assertSame(GuardReason::DestructiveStatement, $verdict->reason);
        self::assertSame(GuardReason::NotReadOnly, (new StatementGuard())->check("REVERT VITESS_MIGRATION 'x'", Engine::MySql, QueryMode::ReadOnly)->reason);
    }

    /**
     * @return iterable<string, array{string, QueryMode, string}>
     */
    public static function redisCommands(): iterable
    {
        // Ce que la liste blanche refusait : avertissement, et en lecture seule le serveur (COMMAND INFO, dans le runner) a le dernier mot.
        foreach (['KEYS *', 'CONFIG GET maxmemory', 'CLIENT LIST', 'SLOWLOG GET 5', 'EVAL "return 1" 0', 'SCRIPT FLUSH', 'FLUSHALL', 'SUBSCRIBE a', 'BLPOP q 1', 'PUBLISH c m', 'OBJECT HELP', 'PFCOUNT hll', 'SHUTDOWN NOSAVE', 'CONFIG SET maxmemory 0'] as $command) {
            yield 'lecture seule '.$command => [$command, QueryMode::ReadOnly, 'confirm:Read'];
            yield 'lecture-écriture '.$command => [$command, QueryMode::ReadWrite, 'confirm:Write'];
        }
        yield 'COPY vers une autre base' => ['COPY a b DB 2', QueryMode::ReadWrite, 'confirm:Write'];
        // Rien d'autre n'est assoupli.
        yield 'écriture en lecture seule' => ['SET a 1', QueryMode::ReadOnly, 'refuse:NotReadOnly'];
        yield 'plusieurs commandes' => ["GET a\nGET b", QueryMode::ReadOnly, 'refuse:MultipleStatements'];
        yield 'session (pas de session côté TerrilDB)' => ['MULTI', QueryMode::ReadWrite, 'refuse:UnsupportedStatement'];
        yield 'lecture ordinaire' => ['GET a', QueryMode::ReadOnly, 'allow:Read'];
        yield 'FLUSHDB' => ['FLUSHDB', QueryMode::ReadWrite, 'confirm:DestructiveStatement'];
    }

    #[DataProvider('redisCommands')]
    public function testRedisCommandsOutsideTheWhitelistAskForConfirmationInLenientMode(string $command, QueryMode $mode, string $expected): void
    {
        $verdict = (new StatementGuard())->check($command, Engine::Redis, $mode, lenient: true);

        $actual = match ($verdict->decision) {
            Decision::Allowed => 'allow:'.(string) $verdict->kind?->name,
            Decision::NeedsConfirmation => 'confirm:'.(string) ('confirm:Read' === $expected || 'confirm:Write' === $expected ? $verdict->kind?->name : $verdict->reason?->name),
            Decision::Refused => 'refuse:'.(string) $verdict->reason?->name,
        };
        self::assertSame($expected, $actual, $command);
    }

    public function testRedisKeepsItsStrictVerdictsForTokensAndTheMcp(): void
    {
        foreach (['KEYS *', 'CONFIG GET maxmemory', 'EVAL "return 1" 0', 'PFCOUNT hll'] as $command) {
            self::assertSame(Decision::Refused, (new StatementGuard())->check($command, Engine::Redis, QueryMode::ReadOnly)->decision, $command);
        }
    }

    public function testARedisWriteWithoutTheRightStaysRefusedWhenLenient(): void
    {
        $verdict = (new StatementGuard())->check('FLUSHALL', Engine::Redis, QueryMode::ReadWrite, new \App\Core\Query\WritePermissions(false, false), lenient: true);

        self::assertSame(GuardReason::WriteNotPermitted, $verdict->reason);
    }
}

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
}

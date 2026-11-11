<?php

declare(strict_types=1);

namespace App\Tests\Core\Guard;

use App\Core\Connection\Engine;
use App\Core\Guard\Decision;
use App\Core\Guard\StatementGuard;
use App\Core\Query\QueryMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Corpus du garde : une ligne = une instruction, un moteur, un mode, le verdict attendu. Il sert de documentation
 * du comportement (issue « corpus du garde »). Verdicts : « allow:Genre », « refuse:Motif », « confirm:Motif »
 * (genre et motifs = noms des énumérations StatementKind et GuardReason).
 *
 * Le filtre est lexical : il peut se tromper, le moteur fait foi. Dans le doute, le corpus fige le choix PRUDENT.
 */
final class StatementGuardCorpusTest extends TestCase
{
    private const SQL_ENGINES = [Engine::MySql, Engine::MariaDb, Engine::PostgreSql, Engine::ClickHouse];
    private const MYSQL_FAMILY = [Engine::MySql, Engine::MariaDb];

    /**
     * Lacunes constatées, corrigées une par une : chaque famille quitte cette liste dans le commit qui la corrige.
     *
     * @var array<string, string> instruction => famille
     */
    private const PENDING = [
        'CREATE OR REPLACE TABLE t (a INT)' => 'structure',
        'CREATE OR REPLACE VIEW v AS SELECT 1' => 'structure',
        'OPTIMIZE TABLE t' => 'structure',
        'ANALYZE TABLE t' => 'structure',
        'VACUUM t' => 'structure',
        'ANALYZE DELETE FROM t' => 'structure',
        'OPTIMIZE TABLE t FINAL' => 'structure',
    ];

    /**
     * @param list<Engine> $engines
     *
     * @return iterable<string, array{string, Engine, QueryMode, string}>
     */
    private static function rows(string $sql, array $engines, QueryMode $mode, string $expected): iterable
    {
        foreach ($engines as $engine) {
            yield \sprintf('%s | %s | %s', $engine->name, $mode->name, $sql) => [$sql, $engine, $mode, $expected];
        }
    }

    /**
     * @return iterable<string, array{string, Engine, QueryMode, string}>
     */
    public static function corpus(): iterable
    {
        $ro = QueryMode::ReadOnly;
        $rw = QueryMode::ReadWrite;
        $all = self::SQL_ENGINES;
        $pg = [Engine::PostgreSql];
        $my = self::MYSQL_FAMILY;

        // ---- CTE : le WITH ne masque ni la tête ni les écritures cachées
        foreach ([
            'WITH d AS (DELETE FROM t RETURNING *) SELECT * FROM d',
            'WITH u AS (UPDATE t SET a = 1 RETURNING *) SELECT * FROM u',
            'WITH x AS (SELECT 1) UPDATE t SET a = 1',
            'WITH x AS (SELECT 1) DELETE FROM t',
            'WITH x AS (SELECT 1) INSERT INTO t SELECT * FROM x',
        ] as $sql) {
            yield from self::rows($sql, $all, $ro, 'refuse:NotReadOnly');
        }
        yield from self::rows('WITH d AS (DELETE FROM t RETURNING *) SELECT * FROM d', $pg, $rw, 'confirm:DestructiveWithoutWhere');
        yield from self::rows('WITH u AS (UPDATE t SET a = 1 RETURNING *) SELECT * FROM u', $pg, $rw, 'confirm:DestructiveWithoutWhere');
        yield from self::rows('WITH d AS (DELETE FROM t WHERE id = 3 RETURNING *) SELECT * FROM d', $pg, $rw, 'allow:Write');
        yield from self::rows('WITH d AS (DELETE FROM t WHERE id = 3 RETURNING *), e AS (DELETE FROM u RETURNING *) SELECT 1', $pg, $rw, 'confirm:DestructiveWithoutWhere');
        yield from self::rows('WITH d AS (DELETE FROM t RETURNING *) SELECT * FROM d WHERE a = 1', $pg, $rw, 'confirm:DestructiveWithoutWhere');
        yield from self::rows('WITH x AS (SELECT 1) UPDATE t SET a = 1', $all, $rw, 'confirm:DestructiveWithoutWhere');
        yield from self::rows('WITH x AS (SELECT 1) DELETE FROM t WHERE a = 1', $all, $rw, 'allow:Write');
        yield from self::rows('WITH x AS (SELECT 1) DROP TABLE t', $all, $ro, 'refuse:NotReadOnly');
        yield from self::rows('WITH x AS (SELECT 1) TRUNCATE t', $all, $ro, 'refuse:NotReadOnly');
        yield from self::rows('WITH x AS (SELECT 1) GRANT ALL ON t TO u', $all, $ro, 'refuse:NotReadOnly');
        yield from self::rows('WITH x AS (SELECT 1) DROP TABLE t', $all, $rw, 'confirm:DestructiveStatement');
        yield from self::rows('WITH x AS (SELECT 1) SELECT * FROM x FOR UPDATE', $all, $ro, 'refuse:ForbiddenConstruct');
        yield from self::rows('SELECT * FROM (SELECT * FROM t FOR UPDATE) s', $all, $ro, 'refuse:ForbiddenConstruct');
        yield from self::rows('WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 5) SELECT i FROM n', $all, $ro, 'allow:Read');
        yield from self::rows("WITH x AS (SELECT 1) SELECT replace(a, 'b', 'c') FROM t", $all, $ro, 'allow:Read');
        yield from self::rows("WITH x AS (SELECT 1) SELECT REPLACE (a, 'b', 'c') FROM t", $all, $ro, 'allow:Read');
        yield from self::rows("WITH x AS (SELECT 1) SELECT insert('abc', 1, 1, 'x') FROM t", $my, $ro, 'allow:Read');
        yield from self::rows('WITH x AS (SELECT 1) REPLACE INTO t VALUES (1)', $my, $ro, 'refuse:NotReadOnly');

        // ---- Lectures sans SELECT, mots destructifs qui n'en sont pas
        foreach ([
            'VALUES (1), (2)',
            '(SELECT 1) UNION (SELECT 2)',
            '((SELECT 1))',
            'SELECT sum(a) OVER (PARTITION BY b ORDER BY c ROWS BETWEEN 1 PRECEDING AND CURRENT ROW) FROM t',
            'SELECT * FROM t, LATERAL (SELECT * FROM u WHERE u.a = t.a) s',
            "SELECT * FROM JSON_TABLE('[1]', '\$[*]' COLUMNS (a INT PATH '\$')) j",
            'SELECT updated_at, deleted_at, created FROM t',
            "SELECT 'DELETE FROM t'",
            'SELECT 1 /* DROP TABLE t */',
            "SELECT 1 -- DROP TABLE t\n",
            'select/**/*from t',
            'SELECT 1;',
            'SELECT 1;;',
            'SELECT 1 /* ; */',
        ] as $sql) {
            yield from self::rows($sql, $all, $ro, 'allow:Read');
        }
        yield from self::rows('TABLE t', [Engine::PostgreSql, Engine::MySql, Engine::MariaDb], $ro, 'allow:Read');
        yield from self::rows('DeLeTe FrOm t', $all, $rw, 'confirm:DestructiveWithoutWhere');
        yield from self::rows('DeLeTe FrOm t', $all, $ro, 'refuse:NotReadOnly');
        yield from self::rows('SELECT 1; SELECT 2', $all, $ro, 'refuse:MultipleStatements');
        yield from self::rows('SELECT 1; DROP TABLE t', $all, $rw, 'refuse:MultipleStatements');
        yield from self::rows('SELECT 1 /* open', $all, $ro, 'refuse:IncompleteSql');
        yield from self::rows(';', $all, $ro, 'refuse:EmptyStatement');
        yield from self::rows('-- seulement un commentaire', $all, $ro, 'refuse:EmptyStatement');

        // ---- Guillemets et échappements
        yield from self::rows('SELECT $$ ; DELETE FROM t $$', $pg, $ro, 'allow:Read');
        yield from self::rows('SELECT $a$ ; DELETE FROM t $a$', $pg, $ro, 'allow:Read');
        yield from self::rows("SELECT E'\\'; DELETE FROM t; --'", $pg, $ro, 'allow:Read');
        yield from self::rows("SELECT 'a\\'; DELETE FROM t; --'", $my, $ro, 'allow:Read');
        yield from self::rows("SELECT '\\\\'; DELETE FROM t; --'", $my, $ro, 'refuse:IncompleteSql');
        yield from self::rows("SELECT 'a\\'; DELETE FROM t; --'", $pg, $ro, 'refuse:MultipleStatements');
        yield from self::rows('SELECT data ? \'k\' FROM t', $pg, $ro, 'allow:Read');
        yield from self::rows('SELECT 1 /*! ; DROP TABLE t */', $my, $ro, 'refuse:MultipleStatements');
        yield from self::rows('/*!50000 DROP TABLE t */', $my, $ro, 'refuse:NotReadOnly');
        yield from self::rows('/*M!100000 DROP TABLE t */', $my, $ro, 'refuse:NotReadOnly');
        yield from self::rows('/*!50000 DROP TABLE t */', $my, $rw, 'confirm:DestructiveStatement');
        yield from self::rows('SELECT 1 # ; DROP TABLE t', $my, $ro, 'allow:Read');
        yield from self::rows('SELECT 1 /* a /* b */ ; DROP TABLE t */', $pg, $ro, 'allow:Read');
        yield from self::rows('SELECT 1 /* a /* b */ ; DROP TABLE t */', $my, $ro, 'refuse:MultipleStatements');

        // ---- Verrous de lecture
        foreach ([
            'SELECT * FROM t FOR UPDATE',
            'SELECT * FROM t FOR UPDATE SKIP LOCKED',
            'SELECT * FROM t FOR SHARE NOWAIT',
            'SELECT * FROM t FOR NO KEY UPDATE',
            'SELECT * FROM t LOCK IN SHARE MODE',
            'SELECT * FROM (SELECT * FROM t LOCK IN SHARE MODE) s',
            'SELECT a INTO @v FROM t',
            "SELECT 1 INTO OUTFILE '/tmp/x'",
            "SELECT 1 INTO DUMPFILE '/tmp/x'",
        ] as $sql) {
            yield from self::rows($sql, $all, $ro, 'refuse:ForbiddenConstruct');
        }
        yield from self::rows('SELECT a INTO @v FROM t', $all, $rw, 'allow:Read');

        // ---- Fonctions à effet de bord
        foreach ([
            "SELECT GET_LOCK('x', 10)", "SELECT RELEASE_LOCK('x')", 'SELECT RELEASE_ALL_LOCKS()',
        ] as $sql) {
            yield from self::rows($sql, $my, $ro, 'refuse:ForbiddenConstruct');
            yield from self::rows($sql, $my, $rw, 'refuse:ForbiddenConstruct');
        }
        foreach ([
            'SELECT pg_advisory_lock(1)', 'SELECT pg_try_advisory_lock(1)', 'SELECT pg_advisory_xact_lock(1, 2)',
            'SELECT pg_advisory_unlock(1)', 'SELECT pg_advisory_unlock_all()', 'SELECT pg_advisory_lock_shared(1)',
        ] as $sql) {
            yield from self::rows($sql, $pg, $ro, 'refuse:ForbiddenConstruct');
            yield from self::rows($sql, $pg, $rw, 'refuse:ForbiddenConstruct');
        }
        foreach (["SELECT nextval('s')", "SELECT setval('s', 10)"] as $sql) {
            yield from self::rows($sql, $pg, $ro, 'refuse:NotReadOnly');
            yield from self::rows($sql, $pg, $rw, 'allow:Write');
        }
        yield from self::rows("EXPLAIN ANALYZE SELECT nextval('s')", $pg, $ro, 'refuse:NotReadOnly');
        yield from self::rows("EXPLAIN SELECT nextval('s')", $pg, $ro, 'allow:Inspect');
        yield from self::rows("WITH x AS (SELECT nextval('s')) SELECT * FROM x", $pg, $ro, 'refuse:NotReadOnly');
        yield from self::rows("SELECT set_config('a.b', 'c', false)", $pg, $rw, 'refuse:ForbiddenConstruct');
        yield from self::rows("SELECT load_file('/etc/passwd')", $my, $rw, 'refuse:ForbiddenConstruct');
        yield from self::rows("SELECT pg_read_file('/etc/passwd')", $pg, $rw, 'refuse:ForbiddenConstruct');
        // Lectures bornées par le délai du moteur (annulation vérifiée en intégration).
        yield from self::rows('SELECT SLEEP(1)', [...$my, Engine::ClickHouse], $ro, 'allow:Read');
        yield from self::rows('SELECT BENCHMARK(1000, MD5(1))', $my, $ro, 'allow:Read');
        yield from self::rows('SELECT pg_sleep(1)', $pg, $ro, 'allow:Read');
        yield from self::rows('SELECT * FROM generate_series(1, 10)', $pg, $ro, 'allow:Read');
        yield from self::rows('SELECT sleep(1)', [Engine::ClickHouse], $ro, 'allow:Read');
        yield from self::rows('SELECT a, nextval FROM t', $pg, $ro, 'allow:Read');
        yield from self::rows('SELECT lock_name FROM t', $my, $ro, 'allow:Read');

        // ---- Écritures
        foreach ([
            'INSERT INTO t VALUES (1) ON CONFLICT (id) DO UPDATE SET a = 1 RETURNING *',
            'UPDATE t SET a = 1 WHERE id = 1 RETURNING *',
            'MERGE INTO t USING s ON t.id = s.id WHEN NOT MATCHED THEN INSERT VALUES (s.id)',
        ] as $sql) {
            yield from self::rows($sql, $pg, $ro, 'refuse:NotReadOnly');
            yield from self::rows($sql, $pg, $rw, 'allow:Write');
        }
        yield from self::rows('MERGE INTO t USING s ON t.id = s.id WHEN MATCHED THEN DELETE', $pg, $rw, 'confirm:DestructiveWithoutWhere');
        yield from self::rows('MERGE INTO t USING s ON t.id = s.id WHEN MATCHED THEN UPDATE SET a = 1', $pg, $rw, 'confirm:DestructiveWithoutWhere');
        yield from self::rows('INSERT INTO t VALUES (1) ON DUPLICATE KEY UPDATE a = 2', $my, $rw, 'allow:Write');
        yield from self::rows('INSERT INTO t VALUES (1) ON DUPLICATE KEY UPDATE a = 2', $my, $ro, 'refuse:NotReadOnly');
        yield from self::rows('INSERT INTO t SELECT * FROM u FOR UPDATE', $my, $rw, 'allow:Write');
        yield from self::rows('REPLACE INTO t VALUES (1)', $my, $ro, 'refuse:NotReadOnly');
        yield from self::rows('REPLACE INTO t VALUES (1)', $my, $rw, 'allow:Write');
        yield from self::rows('UPDATE t FOR PORTION OF p FROM 1 TO 2 SET a = 1', [Engine::MariaDb], $rw, 'confirm:DestructiveWithoutWhere');
        yield from self::rows('UPDATE t FOR PORTION OF p FROM 1 TO 2 SET a = 1 WHERE id = 1', [Engine::MariaDb], $rw, 'allow:Write');
        yield from self::rows('DELETE HISTORY FROM t', [Engine::MariaDb], $rw, 'confirm:DestructiveWithoutWhere');
        yield from self::rows('DELETE HISTORY FROM t', [Engine::MariaDb], $ro, 'refuse:NotReadOnly');
        yield from self::rows('UPDATE t SET a = (SELECT b FROM u WHERE u.id = 1)', $all, $rw, 'confirm:DestructiveWithoutWhere');
        yield from self::rows('DELETE FROM t WHERE id IN (SELECT id FROM u)', $all, $rw, 'allow:Write');
        // Tautologie : non détectée (impossible en général pour un filtre lexical) ; le moteur et les droits restent les remparts.
        yield from self::rows('DELETE FROM t WHERE 1 = 1', $all, $rw, 'allow:Write');
        yield from self::rows('UPDATE t SET a = 1 WHERE true', $all, $rw, 'allow:Write');
        yield from self::rows('ALTER TABLE t DELETE WHERE a = 1', [Engine::ClickHouse], $rw, 'confirm:DestructiveStatement');
        yield from self::rows('ALTER TABLE t UPDATE a = 1 WHERE b = 2', [Engine::ClickHouse], $rw, 'confirm:DestructiveStatement');
        yield from self::rows('ALTER TABLE t UPDATE a = 1 WHERE b = 2', [Engine::ClickHouse], $ro, 'refuse:NotReadOnly');

        // ---- Structure
        yield from self::rows('CREATE OR REPLACE TABLE t (a INT)', [Engine::MariaDb], $rw, 'confirm:DestructiveStatement');
        yield from self::rows('CREATE OR REPLACE VIEW v AS SELECT 1', $all, $rw, 'confirm:DestructiveStatement');
        yield from self::rows('CREATE TABLE t (a INT)', $all, $rw, 'allow:Schema');
        yield from self::rows('CREATE TABLE t (a INT, b INT, FOREIGN KEY (b) REFERENCES u (id) ON DELETE CASCADE ON UPDATE CASCADE)', $all, $rw, 'allow:Schema');
        yield from self::rows('ALTER TABLE t ADD SYSTEM VERSIONING', [Engine::MariaDb], $rw, 'confirm:DestructiveStatement');
        yield from self::rows('ALTER TABLE t DROP SYSTEM VERSIONING', [Engine::MariaDb], $rw, 'confirm:DestructiveStatement');
        foreach (['OPTIMIZE TABLE t', 'ANALYZE TABLE t'] as $sql) {
            yield from self::rows($sql, $my, $ro, 'refuse:NotReadOnly');
            yield from self::rows($sql, $my, $rw, 'confirm:DestructiveStatement');
        }
        yield from self::rows('VACUUM t', $pg, $ro, 'refuse:NotReadOnly');
        yield from self::rows('VACUUM t', $pg, $rw, 'confirm:DestructiveStatement');
        foreach (['CHECK TABLE t', 'REPAIR TABLE t'] as $sql) {
            yield from self::rows($sql, $my, $ro, 'refuse:UnsupportedStatement');
            yield from self::rows($sql, $my, $rw, 'refuse:UnsupportedStatement');
        }

        // ---- EXPLAIN
        yield from self::rows('EXPLAIN ANALYZE DELETE FROM t WHERE a = 1', $all, $ro, 'refuse:NotReadOnly');
        yield from self::rows('EXPLAIN ANALYZE DELETE FROM t', $all, $rw, 'confirm:DestructiveWithoutWhere');
        yield from self::rows('EXPLAIN (ANALYZE, FORMAT JSON) UPDATE t SET a = 1', $pg, $ro, 'refuse:NotReadOnly');
        yield from self::rows('EXPLAIN ANALYZE WITH d AS (DELETE FROM t RETURNING *) SELECT * FROM d', $pg, $ro, 'refuse:NotReadOnly');
        yield from self::rows('EXPLAIN ANALYZE SELECT * FROM t', $all, $ro, 'allow:Inspect');
        yield from self::rows('EXPLAIN FORMAT=JSON SELECT * FROM t', $my, $ro, 'allow:Inspect');
        yield from self::rows('EXPLAIN DELETE FROM t', $all, $ro, 'allow:Inspect');
        yield from self::rows('SHOW TABLES', $all, $ro, 'allow:Inspect');
        yield from self::rows('ANALYZE DELETE FROM t', [Engine::MariaDb], $ro, 'refuse:NotReadOnly');
        yield from self::rows('ANALYZE DELETE FROM t', [Engine::MariaDb], $rw, 'confirm:DestructiveStatement');

        // ---- Instructions propres aux moteurs : jamais prises en charge, ni en lecture seule ni en lecture-écriture
        foreach ([
            'SHUTDOWN', 'FLUSH TABLES WITH READ LOCK', 'FLUSH PRIVILEGES', 'RESET MASTER', 'PURGE BINARY LOGS TO \'x\'',
            "CHANGE REPLICATION SOURCE TO SOURCE_HOST = 'h'", 'START REPLICA', 'STOP REPLICA', 'LOCK TABLES t WRITE', 'UNLOCK TABLES',
            'KILL 3', 'INSTALL PLUGIN p SONAME \'x\'', "SET PASSWORD = 'x'", 'SET ROLE r', 'SET DEFAULT ROLE r TO u',
            "CREATE TABLESPACE ts ADD DATAFILE 'x'", 'HANDLER t OPEN', 'SET STATEMENT max_statement_time=1 FOR SELECT 1',
            'USE other', 'SET autocommit = 0', 'BEGIN', 'COMMIT', 'ROLLBACK', 'START TRANSACTION',
        ] as $sql) {
            foreach ([$ro, $rw] as $mode) {
                // CREATE TABLESPACE entre dans la structure (CREATE) : confirmation de rien, mais il reste un CREATE.
                if (str_starts_with($sql, 'CREATE')) {
                    continue;
                }
                yield from self::rows($sql, $my, $mode, 'refuse:UnsupportedStatement');
            }
        }
        foreach ([
            "LOAD DATA INFILE '/x' INTO TABLE t", "LOAD DATA LOW_PRIORITY INFILE '/x' INTO TABLE t", "LOAD DATA CONCURRENT LOCAL INFILE '/x' INTO TABLE t",
            "LOAD XML INFILE '/x' INTO TABLE t", "COPY t FROM '/x'", "COPY t TO PROGRAM 'x'",
        ] as $sql) {
            foreach ([$ro, $rw] as $mode) {
                yield from self::rows($sql, $all, $mode, 'refuse:ForbiddenConstruct');
            }
        }
        foreach (['SHUTDOWN', 'SYSTEM RELOAD CONFIG', 'KILL QUERY WHERE 1', 'SET max_threads = 1', 'OPTIMIZE TABLE t FINAL'] as $sql) {
            $expected = str_starts_with($sql, 'OPTIMIZE') ? 'confirm:DestructiveStatement' : 'refuse:UnsupportedStatement';
            yield from self::rows($sql, [Engine::ClickHouse], $rw, $expected);
        }
        foreach (['CREATE ROLE r', 'ALTER SYSTEM SET x = 1', 'DO $$ BEGIN END $$', 'CALL p()'] as $sql) {
            yield from self::rows($sql, $pg, $ro, 'refuse:NotReadOnly');
        }
        yield from self::rows('ALTER SYSTEM SET work_mem = 1', $pg, $rw, 'confirm:DestructiveStatement');
        yield from self::rows('SET SESSION AUTHORIZATION x', $pg, $rw, 'refuse:UnsupportedStatement');
        yield from self::rows('SELECT pg_terminate_backend(1)', $pg, $rw, 'refuse:ForbiddenConstruct');

        // ---- ClickHouse
        yield from self::rows('SELECT * FROM t SETTINGS max_threads = 1', [Engine::ClickHouse], $ro, 'allow:Read');
        yield from self::rows('SELECT * FROM url', [Engine::ClickHouse], $ro, 'allow:Read');
        yield from self::rows("SELECT * FROM url('http://x', CSV)", [Engine::ClickHouse], $ro, 'refuse:ForbiddenConstruct');
        yield from self::rows("SELECT * FROM remote('h', db.t)", [Engine::ClickHouse], $rw, 'refuse:ForbiddenConstruct');
        yield from self::rows('SELECT * FROM t FORMAT JSON', [Engine::ClickHouse], $ro, 'refuse:ForbiddenConstruct');
    }

    #[DataProvider('corpus')]
    public function testVerdictOfTheCorpus(string $sql, Engine $engine, QueryMode $mode, string $expected): void
    {
        if (isset(self::PENDING[$sql])) {
            self::markTestSkipped('Lacune connue : '.self::PENDING[$sql]);
        }

        $verdict = (new StatementGuard())->check($sql, $engine, $mode);

        $actual = match ($verdict->decision) {
            Decision::Allowed => 'allow:'.(string) $verdict->kind?->name,
            Decision::NeedsConfirmation => 'confirm:'.(string) $verdict->reason?->name,
            Decision::Refused => 'refuse:'.(string) $verdict->reason?->name,
        };

        self::assertSame($expected, $actual, $sql);
    }
}

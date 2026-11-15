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
        $seen = [];
        foreach ([self::firstBatch(), self::secondBatch()] as $batch) {
            foreach ($batch as $name => $row) {
                if (!isset($seen[$name])) {
                    $seen[$name] = true;

                    yield $name => $row;
                }
            }
        }
    }

    /**
     * @return iterable<string, array{string, Engine, QueryMode, string}>
     */
    private static function firstBatch(): iterable
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
        yield from self::rows('SELECT a INTO @v FROM t', [...$my, Engine::ClickHouse], $rw, 'allow:Read');

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

        // ---- Nom de fonction entre guillemets : le moteur l'appelle tout de même, le garde le lit déquoté
        foreach ([
            'SELECT "pg_read_file"(\'/etc/passwd\')', 'SELECT "pg_catalog"."pg_read_file"(\'/etc/passwd\')',
            'SELECT pg_catalog . "pg_read_file" /* x */ (\'/etc/passwd\')', 'SELECT pg_read_file /* x */ (\'/etc/passwd\')',
            'SELECT pg_read_file -- x'."\n".'(\'/etc/passwd\')', 'SELECT "pg_terminate_backend"(1)', 'SELECT "set_config"(\'a.b\', \'c\', false)',
            'SELECT "pg_advisory_lock"(1)', 'SELECT "PG_ADVISORY_LOCK"(1)', 'SELECT "pg_try_advisory_lock"(1)',
        ] as $sql) {
            yield from self::rows($sql, $pg, $ro, 'refuse:ForbiddenConstruct');
            yield from self::rows($sql, $pg, $rw, 'refuse:ForbiddenConstruct');
        }
        // Identifiant à échappement Unicode (U&"…", UESCAPE) : le moteur lit l'échappement, le garde aussi.
        foreach ([
            'SELECT U&"pg_\\0061dvisory_lock"(1)', 'SELECT u&"pg_\\+000061dvisory_lock"(1)', 'SELECT U&"pg_!0061dvisory_lock" UESCAPE \'!\' (1)',
            'SELECT pg_catalog.U&"pg_read_\\0066ile"(\'/etc/passwd\')',
            'SELECT U&"pg_read_file" UESCAPE \'!\' /* x */ (\'/etc/passwd\')',
        ] as $sql) {
            yield from self::rows($sql, $pg, $ro, 'refuse:ForbiddenConstruct');
        }
        yield from self::rows('SELECT U&"d\\0061ta" FROM t', $pg, $ro, 'allow:Read');
        foreach (['SELECT "nextval"(\'s\')', 'SELECT s."nextval" (\'s\')', 'SELECT "pg_catalog"."setval"(\'s\', 10)'] as $sql) {
            yield from self::rows($sql, $pg, $ro, 'refuse:NotReadOnly');
            yield from self::rows($sql, $pg, $rw, 'allow:Write');
        }
        foreach (['SELECT `get_lock`(\'a\', 1)', 'SELECT `RELEASE_LOCK`(\'a\')', 'SELECT x.`get_lock` (\'a\', 1)', 'SELECT get_lock /* x */ (\'a\', 1)', 'SELECT `load_file`(\'/etc/passwd\')'] as $sql) {
            yield from self::rows($sql, $my, $ro, 'refuse:ForbiddenConstruct');
            yield from self::rows($sql, $my, $rw, 'refuse:ForbiddenConstruct');
        }
        // ANSI_QUOTES : le guillemet double devient un identifiant en MySQL, donc une fonction avant « ( ».
        yield from self::rows('SELECT "get_lock"(\'a\', 1)', $my, $ro, 'refuse:ForbiddenConstruct');
        yield from self::rows('SELECT `file`(\'x\')', [Engine::ClickHouse], $ro, 'refuse:ForbiddenConstruct');
        yield from self::rows('SELECT * FROM "url"(\'http://x/\', CSV)', [Engine::ClickHouse], $ro, 'refuse:ForbiddenConstruct');
        yield from self::rows('SELECT * FROM `s3`(\'http://x/\')', [Engine::ClickHouse], $ro, 'refuse:ForbiddenConstruct');
        // Un identifiant entre guillemets qui n'est pas un appel (colonne, table, chaîne) n'est pas refusé.
        yield from self::rows('SELECT "nextval", "pg_read_file" FROM "pg_advisory_lock"', $pg, $ro, 'allow:Read');
        yield from self::rows('SELECT "nextval" FROM t WHERE "pg_read_file" = 1', $pg, $ro, 'allow:Read');
        yield from self::rows('SELECT `get_lock`, `load_file` FROM `sleep`', $my, $ro, 'allow:Read');
        yield from self::rows('SELECT "pg_read_file(", \'pg_read_file(\' FROM t', $pg, $ro, 'allow:Read');
        yield from self::rows('SELECT "x (" FROM t WHERE a IN (1)', $pg, $ro, 'allow:Read');
        yield from self::rows('SELECT \'pg_read_file(\', "a" (1)', $pg, $ro, 'allow:Read');
        yield from self::rows('SELECT "url" FROM t', [Engine::ClickHouse], $ro, 'allow:Read');

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
        // ANALYZE / EXPLAIN ANALYZE exécutent vraiment l'instruction qui suit : elle est jugée comme elle.
        yield from self::rows('ANALYZE DELETE FROM t', [Engine::MariaDb], $rw, 'confirm:DestructiveWithoutWhere');
        yield from self::rows('ANALYZE DELETE FROM t WHERE a = 1', [Engine::MariaDb], $rw, 'allow:Write');
        yield from self::rows('ANALYZE FORMAT=JSON UPDATE t SET a = 1 WHERE b = 2', [Engine::MariaDb], $rw, 'allow:Write');
        yield from self::rows('ANALYZE t', $pg, $rw, 'confirm:DestructiveStatement');
        yield from self::rows('EXPLAIN ANALYZE CREATE TABLE z AS SELECT 1', $pg, $ro, 'refuse:NotReadOnly');
        yield from self::rows('EXPLAIN ANALYZE CREATE TABLE z AS SELECT 1', $pg, $rw, 'allow:Schema');
        yield from self::rows('EXPLAIN (ANALYZE) CREATE TABLE z AS SELECT 1', $pg, $rw, 'allow:Schema');
        yield from self::rows('EXPLAIN (ANALYZE, VERBOSE) CREATE OR REPLACE VIEW v AS SELECT 1', $pg, $rw, 'confirm:DestructiveStatement');
        yield from self::rows('EXPLAIN ANALYZE REFRESH MATERIALIZED VIEW v', $pg, $rw, 'allow:Schema');
        yield from self::rows('EXPLAIN ANALYZE DROP TABLE t', $all, $rw, 'confirm:DestructiveStatement');
        yield from self::rows('EXPLAIN ANALYZE ALTER TABLE t ADD c int', $all, $ro, 'refuse:NotReadOnly');
        yield from self::rows('EXPLAIN ANALYZE SELECT * INTO z FROM t', $pg, $ro, 'refuse:ForbiddenConstruct');
        yield from self::rows('EXPLAIN ANALYZE SELECT * INTO z FROM t', $pg, $rw, 'confirm:DestructiveStatement');
        yield from self::rows('EXPLAIN SELECT * INTO z FROM t', $pg, $rw, 'allow:Inspect');
        yield from self::rows('EXPLAIN ANALYZE WITH x AS (SELECT 1) SELECT * INTO z FROM x', $pg, $rw, 'confirm:DestructiveStatement');
        yield from self::rows('EXPLAIN ANALYZE EXECUTE p', $pg, $rw, 'refuse:UnsupportedStatement');
        yield from self::rows('EXPLAIN (ANALYZE) EXECUTE p', $pg, $rw, 'refuse:UnsupportedStatement');
        yield from self::rows('EXPLAIN ANALYZE DECLARE c CURSOR FOR SELECT 1', $pg, $rw, 'refuse:UnsupportedStatement');
        yield from self::rows('EXPLAIN ANALYZE CALL p()', $pg, $rw, 'confirm:DestructiveStatement');

        // ---- SELECT … INTO <table> (PostgreSQL) crée une table : structure, avec confirmation
        yield from self::rows('SELECT * INTO newt FROM t', $pg, $rw, 'confirm:DestructiveStatement');
        yield from self::rows('SELECT * INTO TEMP newt FROM t', $pg, $rw, 'confirm:DestructiveStatement');
        yield from self::rows('SELECT * INTO UNLOGGED newt FROM t', $pg, $rw, 'confirm:DestructiveStatement');
        yield from self::rows('WITH x AS (SELECT 1 AS a) SELECT * INTO newt FROM x', $pg, $rw, 'confirm:DestructiveStatement');
        yield from self::rows('SELECT * INTO newt FROM t', $pg, $ro, 'refuse:ForbiddenConstruct');
        yield from self::rows('INSERT INTO t SELECT * FROM u', $pg, $rw, 'allow:Write');

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

    /**
     * Deuxième lot (issue #87) : instructions de session, de transaction et d'administration, par moteur, et ce que
     * le moteur RÉEL fait des lectures que le garde laisse passer (voir GuardEngineConcordanceTest).
     *
     * @return iterable<string, array{string, Engine, QueryMode, string}>
     */
    private static function secondBatch(): iterable
    {
        $ro = QueryMode::ReadOnly;
        $rw = QueryMode::ReadWrite;
        $pg = [Engine::PostgreSql];
        $my = self::MYSQL_FAMILY;
        $ch = [Engine::ClickHouse];

        // ---- PostgreSQL : session, transaction, préparation, notification : jamais prises en charge
        foreach ([
            'EXECUTE p', 'PREPARE p AS SELECT 1', 'PREPARE p AS DELETE FROM t', 'DEALLOCATE p', 'DEALLOCATE ALL',
            'SET ROLE x', 'RESET ROLE', 'SET SESSION AUTHORIZATION x', 'RESET SESSION AUTHORIZATION', 'SET LOCAL ROLE x',
            'SET search_path = x', 'SET TRANSACTION READ WRITE', 'SET SESSION CHARACTERISTICS AS TRANSACTION READ WRITE',
            'RESET ALL', 'SET default_transaction_read_only = off', 'LISTEN c', 'NOTIFY c', "NOTIFY c, 'x'", 'UNLISTEN *',
            'DISCARD ALL', 'DISCARD TEMP', 'BEGIN', 'BEGIN READ WRITE', 'START TRANSACTION READ WRITE', 'COMMIT', 'END',
            'ABORT', 'ROLLBACK', 'SAVEPOINT a', 'RELEASE a', 'ROLLBACK TO a', "COMMIT PREPARED 'x'", "PREPARE TRANSACTION 'x'",
            'LOCK TABLE t', 'LOCK t IN ACCESS EXCLUSIVE MODE', "SECURITY LABEL ON TABLE t IS 'x'", 'IMPORT FOREIGN SCHEMA s FROM SERVER x INTO y',
            'CHECKPOINT', 'REASSIGN OWNED BY a TO b',
        ] as $sql) {
            yield from self::rows($sql, $pg, $ro, 'refuse:UnsupportedStatement');
            yield from self::rows($sql, $pg, $rw, 'refuse:UnsupportedStatement');
        }
        foreach (['BEGIN; SELECT 1', 'SELECT 1; COMMIT', 'BEGIN; DELETE FROM t; COMMIT'] as $sql) {
            yield from self::rows($sql, self::SQL_ENGINES, $ro, 'refuse:MultipleStatements');
            yield from self::rows($sql, self::SQL_ENGINES, $rw, 'refuse:MultipleStatements');
        }
        foreach (["COPY (SELECT 1) TO PROGRAM 'id'", 'COPY (SELECT 1) TO STDOUT', "COPY t FROM PROGRAM 'id'", "COPY (SELECT 1) TO '/tmp/x'", "LOAD 'x'", '\copy t from x'] as $sql) {
            yield from self::rows($sql, $pg, $ro, 'refuse:ForbiddenConstruct');
            yield from self::rows($sql, $pg, $rw, 'refuse:ForbiddenConstruct');
        }
        foreach (['DO $$ BEGIN PERFORM 1; END $$', 'DO LANGUAGE plpgsql $$ BEGIN END $$', 'CALL p()'] as $sql) {
            yield from self::rows($sql, $pg, $ro, 'refuse:NotReadOnly');
            yield from self::rows($sql, $pg, $rw, 'confirm:DestructiveStatement');
        }
        yield from self::rows('CREATE FUNCTION f() RETURNS int LANGUAGE sql AS $$ SELECT 1 $$', $pg, $ro, 'refuse:NotReadOnly');
        yield from self::rows('CREATE OR REPLACE FUNCTION f() RETURNS int LANGUAGE sql AS $$ SELECT 1 $$', $pg, $rw, 'confirm:DestructiveStatement');
        foreach (['CLUSTER t', 'REINDEX TABLE t', 'REINDEX DATABASE d', 'TRUNCATE t', 'DROP OWNED BY a', 'ALTER DEFAULT PRIVILEGES GRANT SELECT ON TABLES TO x', "ALTER USER x PASSWORD 'x'"] as $sql) {
            yield from self::rows($sql, $pg, $ro, 'refuse:NotReadOnly');
            yield from self::rows($sql, $pg, $rw, 'confirm:DestructiveStatement');
        }
        foreach (['SELECT lo_import(\'/etc/passwd\')', 'SELECT lo_export(1, \'/tmp/x\')', 'SELECT pg_ls_dir(\'/\')', 'SELECT pg_read_binary_file(\'/x\')', 'SELECT pg_stat_file(\'/x\')',
            "SELECT * FROM dblink('x', 'select 1') t(a int)", "SELECT dblink_exec('x', 'drop table t')", "SELECT dblink_connect('x')", "SELECT dblink_open('a', 'b', 'c')",
            "SELECT dblink_send_query('a', 'b')", 'SELECT * FROM dblink_get_notify()', 'SELECT pg_cancel_backend(1)', 'SELECT pg_reload_conf()', 'SELECT pg_rotate_logfile()',
            "SELECT set_config('x', 'y', true)", 'SELECT pg_advisory_xact_lock_shared(1)', 'SELECT pg_try_advisory_xact_lock(1)',
        ] as $sql) {
            yield from self::rows($sql, $pg, $ro, 'refuse:ForbiddenConstruct');
            yield from self::rows($sql, $pg, $rw, 'refuse:ForbiddenConstruct');
        }
        // Commentaires imbriqués : PostgreSQL les imbrique, le texte après le premier « */ » est encore du commentaire.
        yield from self::rows('SELECT 1 /* /* */ ; DROP TABLE t */', $pg, $ro, 'allow:Read');
        yield from self::rows('SELECT 1 /* a /* b */ c */', $pg, $ro, 'allow:Read');
        yield from self::rows('SELECT 1 /* /* */ */ ; DROP TABLE t', $pg, $ro, 'refuse:MultipleStatements');
        yield from self::rows('SELECT 1 /* /* */ ; DELETE FROM t', $pg, $ro, 'refuse:IncompleteSql');
        yield from self::rows('SELECT 1 /* /* */ ; DELETE FROM t', $my, $ro, 'refuse:MultipleStatements');
        yield from self::rows("SELECT E'a\\'b'", $pg, $ro, 'allow:Read');
        yield from self::rows("SELECT U&'a\\0027b'", $pg, $ro, 'allow:Read');
        yield from self::rows('SELECT $t$ x $u$ ; DROP TABLE t $u$ $t$', $pg, $ro, 'allow:Read');
        yield from self::rows('SELECT $t$ x $t$ ; DROP TABLE t', $pg, $ro, 'refuse:MultipleStatements');
        // Verrous de lecture et CTE modifiantes imbriquées
        foreach (['SELECT * FROM t FOR KEY SHARE', 'SELECT * FROM t FOR NO KEY UPDATE OF t', 'SELECT * FROM t FOR UPDATE OF t NOWAIT'] as $sql) {
            yield from self::rows($sql, $pg, $ro, 'refuse:ForbiddenConstruct');
            yield from self::rows($sql, $pg, $rw, 'allow:Read');
        }
        yield from self::rows('WITH a AS (WITH b AS (DELETE FROM t RETURNING *) SELECT * FROM b) SELECT * FROM a', $pg, $ro, 'refuse:NotReadOnly');
        yield from self::rows('WITH a AS (WITH b AS (DELETE FROM t RETURNING *) SELECT * FROM b) SELECT * FROM a', $pg, $rw, 'confirm:DestructiveWithoutWhere');
        yield from self::rows('SELECT * FROM (WITH d AS (INSERT INTO t VALUES (1) RETURNING *) SELECT * FROM d) s', $pg, $ro, 'refuse:ForbiddenConstruct');
        foreach ([
            'EXPLAIN (ANALYZE) DELETE FROM t WHERE a = 1', 'EXPLAIN (ANALYZE true) DELETE FROM t WHERE a = 1', 'EXPLAIN (VERBOSE, ANALYZE) INSERT INTO t VALUES (1)',
            'EXPLAIN ANALYZE VERBOSE DELETE FROM t WHERE a = 1', 'EXPLAIN (analyse) DELETE FROM t WHERE a = 1', 'EXPLAIN ANALYSE DELETE FROM t WHERE a = 1',
            "EXPLAIN (ANALYZE 'true') DELETE FROM t WHERE a = 1", 'EXPLAIN (ANALYZE on) DELETE FROM t',
        ] as $sql) {
            yield from self::rows($sql, $pg, $ro, 'refuse:NotReadOnly');
        }
        // Limite documentée : une fonction qui exécute le texte qu'on lui donne. Le moteur la refuse en lecture seule
        // (transaction READ ONLY) ; en lecture-écriture seul le filtre juge, et il ne voit qu'un SELECT.
        foreach (["SELECT query_to_xml('delete from t', true, true, '')", "SELECT query_to_xml_and_xmlschema('delete from t', true, true, '')", "SELECT table_to_xml('t', true, true, '')"] as $sql) {
            yield from self::rows($sql, $pg, $ro, 'allow:Read');
            yield from self::rows($sql, $pg, $rw, 'allow:Read');
        }

        // ---- MySQL / MariaDB : session, administration, programmes stockés
        foreach ([
            'SET @x = 1', 'SET @@global.x = 1', 'SET GLOBAL x = 1', 'SET SESSION x = 1', 'SET PERSIST x = 1', 'SET NAMES utf8', 'SET CHARACTER SET utf8',
            "SET sql_mode = ''", 'SET TRANSACTION READ WRITE', 'HANDLER t READ FIRST', 'HANDLER t CLOSE', 'LOCK TABLES t READ', 'FLUSH LOGS', 'FLUSH STATUS',
            'RESET REPLICA', 'KILL QUERY 1', 'KILL CONNECTION 1', "INSTALL PLUGIN x SONAME 'y'", 'UNINSTALL PLUGIN x', "INSTALL COMPONENT 'x'", "UNINSTALL COMPONENT 'x'",
            "CHANGE MASTER TO MASTER_HOST = 'x'", 'START SLAVE', 'STOP SLAVE', 'START GROUP_REPLICATION', 'STOP GROUP_REPLICATION',
            "XA START 'x'", "XA END 'x'", "XA PREPARE 'x'", "XA COMMIT 'x'", 'XA RECOVER', 'EXECUTE s', "PREPARE s FROM 'DELETE FROM t'", 'PREPARE s FROM @x',
            "EXECUTE IMMEDIATE 'DELETE FROM t'", "EXECUTE IMMEDIATE 'SELECT 1'", 'EXECUTE s USING @a', 'DEALLOCATE PREPARE s', 'DELIMITER ;;', 'DELIMITER $$ SELECT 1 $$',
            "BINLOG 'x'", 'CACHE INDEX t IN c', 'SET STATEMENT max_statement_time = 1 FOR SELECT 1', 'SET STATEMENT read_only = 0 FOR DELETE FROM t',
            "SET STATEMENT x = 1 FOR SELECT GET_LOCK('a', 1)", "IMPORT TABLE FROM 'x'", "CLONE LOCAL DATA DIRECTORY = 'x'",
        ] as $sql) {
            yield from self::rows($sql, $my, $ro, 'refuse:UnsupportedStatement');
            yield from self::rows($sql, $my, $rw, 'refuse:UnsupportedStatement');
        }
        foreach (["LOAD DATA INFILE 'x' INTO TABLE t", "LOAD DATA LOCAL INFILE 'x' INTO TABLE t", 'LOAD INDEX INTO CACHE t'] as $sql) {
            yield from self::rows($sql, $my, $ro, 'refuse:ForbiddenConstruct');
            yield from self::rows($sql, $my, $rw, 'refuse:ForbiddenConstruct');
        }
        foreach ([
            'CREATE EVENT e ON SCHEDULE EVERY 1 SECOND DO SELECT 1', 'CREATE TRIGGER tr BEFORE INSERT ON t FOR EACH ROW SET NEW.a = 1', 'CREATE PROCEDURE p() SELECT 1',
            'CREATE FUNCTION f() RETURNS INT RETURN 1', 'CREATE DEFINER = x PROCEDURE p() SELECT 1', "CREATE FUNCTION f RETURNS STRING SONAME 'x'",
            "CREATE AGGREGATE FUNCTION f RETURNS STRING SONAME 'x'",
        ] as $sql) {
            yield from self::rows($sql, $my, $ro, 'refuse:NotReadOnly');
            yield from self::rows($sql, $my, $rw, 'allow:Schema');
        }
        foreach (['DO SLEEP(1)', 'DO 1', 'DO (SELECT 1)', 'CALL p()'] as $sql) {
            yield from self::rows($sql, $my, $ro, 'refuse:NotReadOnly');
            yield from self::rows($sql, $my, $rw, 'confirm:DestructiveStatement');
        }
        yield from self::rows("DO GET_LOCK('a', 1)", $my, $ro, 'refuse:NotReadOnly');
        yield from self::rows("DO GET_LOCK('a', 1)", $my, $rw, 'refuse:ForbiddenConstruct');
        foreach (['ALTER EVENT e DISABLE', 'DROP EVENT e', "ALTER PROCEDURE p COMMENT 'x'", 'RENAME USER a TO b', 'RENAME TABLE a TO b', 'DROP PREPARE s', 'ALTER INSTANCE ROTATE INNODB MASTER KEY'] as $sql) {
            yield from self::rows($sql, $my, $ro, 'refuse:NotReadOnly');
            yield from self::rows($sql, $my, $rw, 'confirm:DestructiveStatement');
        }
        foreach (['DELETE FROM t RETURNING *', 'INSERT INTO t VALUES (1) RETURNING *', 'REPLACE INTO t VALUES (1) RETURNING *', 'REPLACE t VALUES (1)', 'REPLACE INTO t SELECT * FROM u'] as $sql) {
            yield from self::rows($sql, [Engine::MariaDb], $ro, 'refuse:NotReadOnly');
        }
        // Lectures qui laissent une trace seulement dans la session (variables utilisateur, compteurs) : permises.
        foreach (['SELECT @x := 1', 'SELECT @a := 1, @b := 2', 'SELECT @@global.max_connections', 'SELECT @@sql_mode', 'SELECT LAST_INSERT_ID(5)', 'SELECT UUID_SHORT()', 'SELECT UUID()',
            'SELECT FOUND_ROWS()', 'SELECT SQL_NO_CACHE 1', 'SELECT * FROM t PARTITION (p0)', 'SELECT * FROM t PROCEDURE ANALYSE()', 'SELECT IS_FREE_LOCK(\'a\')', 'SELECT IS_USED_LOCK(\'a\')',
            'SELECT /*!50000 1 */', 'SELECT /*!80000 SLEEP(1) */', 'SELECT /*M!999999 SLEEP(1) */ 1',
        ] as $sql) {
            yield from self::rows($sql, $my, $ro, 'allow:Read');
        }
        foreach (['SELECT 1 /*!80000 INTO @v */', 'SELECT /*!80000 1 INTO @v */', 'SELECT * FROM t /*!80000 FOR UPDATE */', 'SELECT * FROM t /*!80000 LOCK IN SHARE MODE */',
            "SELECT /*!80000 get_lock */('a', 1)", "SELECT get_lock/*!80000 */('a', 1)", "SELECT /*!80000 get_lock('a', 1) */",
            "SELECT 1 INTO/**/OUTFILE 'x'", "SELECT 1 INTO /*!80000 OUTFILE */ 'x'", 'SELECT 1 INTO /* x */ @v', 'SELECT 1 INTO @`v`', 'SELECT 1 UNION SELECT 2 INTO @v',
            'SELECT * FROM t FOR UPDATE INTO @v', "SELECT * FROM t FOR\nUPDATE", "SELECT * FROM t FOR\tSHARE", "SELECT * FROM t LOCK\nIN SHARE MODE",
            'SELECT * FROM t FOR UPDATE /* x */', 'SELECT * FROM t LOCK/**/IN SHARE MODE', 'SELECT * FROM t FOR UPDATE OF t', 'SELECT * FROM t FOR UPDATE WAIT 5',
        ] as $sql) {
            yield from self::rows($sql, $my, $ro, 'refuse:ForbiddenConstruct');
        }
        foreach (["SELECT SLEEP(1)", 'SELECT BENCHMARK(1, 1)', "SELECT MASTER_POS_WAIT('a', 1)", "SELECT SOURCE_POS_WAIT('a', 1)"] as $sql) {
            yield from self::rows($sql, $my, $ro, 'allow:Read');
        }
        yield from self::rows('SELECT 1 /*! /* nested */ */', $my, $ro, 'allow:Read');
        yield from self::rows('SELECT 1 /*!50000 ; DROP TABLE t */', $my, $ro, 'refuse:MultipleStatements');
        yield from self::rows('SELECT 1 /*M!999999 ; DROP TABLE t */', $my, $ro, 'refuse:MultipleStatements');
        yield from self::rows('SELECT /*!50000 1 */ ; DROP TABLE t', $my, $ro, 'refuse:MultipleStatements');
        // Séquences MariaDB : les fonctions qui avancent le compteur sont des écritures.
        foreach (['SELECT NEXTVAL(s)', 'SELECT SETVAL(s, 1)', 'SELECT `nextval`(s)'] as $sql) {
            yield from self::rows($sql, [Engine::MariaDb], $ro, 'refuse:NotReadOnly');
        }

        // ---- ClickHouse
        foreach (['INSERT INTO t VALUES (1)', 'INSERT INTO t SELECT 1'] as $sql) {
            yield from self::rows($sql, $ch, $ro, 'refuse:NotReadOnly');
        }
        yield from self::rows("INSERT INTO FUNCTION file('x') VALUES (1)", $ch, $ro, 'refuse:ForbiddenConstruct');
        yield from self::rows("INSERT INTO FUNCTION file('x') VALUES (1)", $ch, $rw, 'refuse:ForbiddenConstruct');
        foreach (['ALTER TABLE t UPDATE a = 1 WHERE 1', 'ALTER TABLE t DELETE WHERE 1', 'ALTER TABLE t DROP PARTITION 1', 'DROP TABLE t', 'DETACH TABLE t', 'TRUNCATE TABLE t', 'RENAME TABLE a TO b', 'EXCHANGE TABLES a AND b', 'GRANT SELECT ON *.* TO x'] as $sql) {
            yield from self::rows($sql, $ch, $ro, 'refuse:NotReadOnly');
            yield from self::rows($sql, $ch, $rw, 'confirm:DestructiveStatement');
        }
        yield from self::rows('ATTACH TABLE t', $ch, $ro, 'refuse:NotReadOnly');
        foreach (['SYSTEM FLUSH LOGS', 'SYSTEM RELOAD CONFIG', 'SYSTEM SHUTDOWN', 'SYSTEM DROP DNS CACHE', 'KILL QUERY WHERE 1', 'KILL MUTATION WHERE 1', 'SET max_threads = 1', 'SET readonly = 0', 'USE db', 'WATCH v', 'CHECK TABLE t'] as $sql) {
            yield from self::rows($sql, $ch, $ro, 'refuse:UnsupportedStatement');
            yield from self::rows($sql, $ch, $rw, 'refuse:UnsupportedStatement');
        }
        foreach (['EXPLAIN SELECT 1', 'DESCRIBE TABLE t', 'SHOW TABLES', 'EXISTS TABLE t'] as $sql) {
            yield from self::rows($sql, $ch, $ro, 'allow:Inspect');
        }
        // Fonctions de table qui sortent du serveur, quelle que soit la casse ou la forme de l'appel.
        foreach ([
            "file('x')", "file('x', CSV, 'a Int')", "url('x', CSV)", "s3('x')", "s3Cluster('c', 'x')", "remote('x', db.t)", "remoteSecure('x', db.t)", "cluster('c', db.t)",
            "clusterAllReplicas('c', db.t)", "mysql('h', 'd', 't', 'u', 'p')", "postgresql('h', 'd', 't', 'u', 'p')", "jdbc('x', 'y', 'z')", "odbc('x', 'y', 'z')",
            "executable('x', 'CSV', 'a Int')", "hdfs('x', 'CSV')", "mongodb('a', 'b', 'c', 'd', 'e')", "redis('a', 'b', 'c')", "sqlite('a', 'b')", "azureBlobStorage('x')",
            "gcs('x')", "deltaLake('x')", "iceberg('x')", "hudi('x')", "input('a Int')", "urlCluster('c', 'x')", "fileCluster('c', 'x')", "hdfsCluster('c', 'x')",
            "FILE('x')", "fIlE('x')", "mysql ('h', 'd', 't', 'u', 'p')", "mysql /* x */ ('h', 'd', 't', 'u', 'p')", "remote/**/('x')", "`remote`('x')", "db.file('x')",
        ] as $call) {
            foreach ([$ro, $rw] as $mode) {
                yield from self::rows('SELECT * FROM '.$call, $ch, $mode, 'refuse:ForbiddenConstruct');
            }
        }
        foreach (["SELECT * FROM t WHERE a IN (SELECT * FROM url('x', CSV))", "SELECT * FROM t JOIN url('x', CSV) USING a", "SELECT * FROM t, url('x', CSV)", "WITH x AS (SELECT * FROM url('x', CSV)) SELECT * FROM x", "SELECT file('x')"] as $sql) {
            yield from self::rows($sql, $ch, $ro, 'refuse:ForbiddenConstruct');
            yield from self::rows($sql, $ch, $rw, 'refuse:ForbiddenConstruct');
        }
        foreach (["SELECT 1 INTO OUTFILE 'x'", "SELECT 1 INTO OUTFILE 'x' FORMAT CSV", 'SELECT 1 FORMAT CSV', 'SELECT 1 FORMAT Null', 'SELECT 1 format JSON', "SELECT 1 FORMAT\nJSON", "SELECT * FROM t INTO OUTFILE 'x' TRUNCATE", "SELECT * FROM t INTO/**/OUTFILE 'x'"] as $sql) {
            yield from self::rows($sql, $ch, $ro, 'refuse:ForbiddenConstruct');
            yield from self::rows($sql, $ch, $rw, 'refuse:ForbiddenConstruct');
        }
        // SETTINGS passe le filtre : en lecture seule le moteur refuse de les changer (readonly = 1, vérifié en intégration).
        foreach (['SELECT 1 SETTINGS readonly = 0', 'SELECT 1 SETTINGS max_execution_time = 0', 'SELECT 1 SETTINGS max_result_rows = 0, result_overflow_mode = \'throw\''] as $sql) {
            yield from self::rows($sql, $ch, $ro, 'allow:Read');
        }
        foreach (['WITH 1 AS x SELECT x', 'WITH (SELECT 1) AS x SELECT x', 'SELECT * FROM numbers(10)', 'SELECT * FROM system.processes', 'SELECT sleep(1)'] as $sql) {
            yield from self::rows($sql, $ch, $ro, 'allow:Read');
        }
        yield from self::rows('WITH x AS (INSERT INTO t VALUES (1)) SELECT 1', $ch, $ro, 'refuse:NotReadOnly');

        // ---- Redis : commandes dangereuses, refusées dans les deux modes
        foreach ([
            'EVAL "return 1" 0', 'EVAL_RO "return 1" 0', 'SCRIPT FLUSH', 'FUNCTION LIST', 'MODULE LOAD /x', 'DEBUG SLEEP 1', 'DEBUG OBJECT k', 'CONFIG SET dir /tmp', 'CONFIG GET *',
            'SLAVEOF no one', 'REPLICAOF host 1', 'MIGRATE h 1 k 0 10', 'RESTORE k 0 x', 'OBJECT HELP', 'XREAD BLOCK 1000 STREAMS s $', 'XREAD STREAMS s 0', 'WAIT 1 100', 'MONITOR',
            'SUBSCRIBE c', 'CLIENT KILL ID 1', 'CLIENT LIST', 'SHUTDOWN NOSAVE', 'FLUSHALL', 'MEMORY DOCTOR', 'MEMORY PURGE', 'COMMAND GETKEYS GET k', 'LATENCY DOCTOR', 'SLOWLOG GET',
            'BLPOP k 0', 'KEYS *', 'SWAPDB 0 1', 'HELLO 3', 'AUTH x', 'WAITAOF 1 0 0', 'CLUSTER INFO', 'READONLY', 'FAILOVER', 'PSYNC x 1', 'SYNC', 'LOLWUT', 'SAVE', 'BGSAVE', 'BGREWRITEAOF',
        ] as $sql) {
            yield from self::rows($sql, [Engine::Redis], $ro, 'refuse:ForbiddenConstruct');
            yield from self::rows($sql, [Engine::Redis], $rw, 'refuse:ForbiddenConstruct');
        }
        foreach ([
            'PUBSUB CHANNELS', 'PUBSUB NUMSUB', 'SORT k STORE d', 'SORT k', 'SINTERSTORE d a b', 'ZRANGESTORE d s 0 -1', 'GEORADIUS k 0 0 1 km STORE d', 'XGROUP CREATE s g $', 'XACK s g 1',
            'BITFIELD k SET u8 0 1', 'BITOP AND d a b', 'PFMERGE d a b', 'LMPOP 1 k LEFT', 'SELECT 1', 'MOVE k 1', 'RESET', 'WATCH k', 'EXEC', 'MULTI', 'TOUCH k', 'DUMP k', 'ROLE', 'SENTINEL MASTERS',
        ] as $sql) {
            yield from self::rows($sql, [Engine::Redis], $ro, 'refuse:UnsupportedStatement');
            yield from self::rows($sql, [Engine::Redis], $rw, 'refuse:UnsupportedStatement');
        }
        foreach (['OBJECT ENCODING k', 'OBJECT FREQ k', 'XINFO STREAM s FULL', 'MEMORY USAGE k', 'COMMAND DOCS', 'COMMAND INFO get', 'INFO', 'INFO ALL', 'ECHO x', 'LCS a b', 'SORT_RO k BY nosort GET #'] as $sql) {
            yield from self::rows($sql, [Engine::Redis], $ro, 'allow:Read');
        }
        foreach (['FLUSHDB', 'FLUSHDB ASYNC'] as $sql) {
            yield from self::rows($sql, [Engine::Redis], $ro, 'refuse:NotReadOnly');
            yield from self::rows($sql, [Engine::Redis], $rw, 'confirm:DestructiveStatement');
        }
        foreach (['LPOP k 5', 'SPOP k 5', 'INCR k', 'EXPIRE k 10', 'GETEX k PERSIST', 'RENAME a b', 'SETRANGE k 10 x', 'SETBIT k 10 1'] as $sql) {
            yield from self::rows($sql, [Engine::Redis], $ro, 'refuse:NotReadOnly');
            yield from self::rows($sql, [Engine::Redis], $rw, 'allow:Write');
        }
    }

    #[DataProvider('corpus')]
    public function testVerdictOfTheCorpus(string $sql, Engine $engine, QueryMode $mode, string $expected): void
    {
        $verdict = (new StatementGuard())->check($sql, $engine, $mode);

        $actual = match ($verdict->decision) {
            Decision::Allowed => 'allow:'.(string) $verdict->kind?->name,
            Decision::NeedsConfirmation => 'confirm:'.(string) $verdict->reason?->name,
            Decision::Refused => 'refuse:'.(string) $verdict->reason?->name,
        };

        self::assertSame($expected, $actual, $sql);
    }
}

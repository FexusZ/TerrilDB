<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Guard\StatementKind;
use App\Core\Query\Exception\QueryFailedException;
use App\Core\Query\Exception\QueryTimeoutException;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Issue #86, points 8 et 10 : délais côté moteur et session du client (jeu de caractères, fuseau, sql_mode), contre MySQL 8.4/26 et
 * MariaDB 11.8/13, sur des tables jetables.
 */
#[Group('integration')]
final class MySqlFamilySessionTest extends TestCase
{
    use MySqlFamilyTarget;

    private static function write(): QueryOptions
    {
        return new QueryOptions(QueryMode::ReadWrite, 300, 100);
    }

    // Point 8 : délais côté moteur.

    /**
     * Mesuré : MariaDB (`max_statement_time`, secondes fractionnaires) coupe AUSSI les écritures, et l'écriture coupée est annulée ;
     * MySQL (`MAX_EXECUTION_TIME`) ne coupe que les SELECT : l'écriture va à son terme, plus longue que le délai demandé.
     */
    #[DataProvider('targets')]
    public function testAWriteIsCutByTheEngineOnMariaDbAndNotOnMySql(string $env): void
    {
        $this->open($env);
        $name = $this->table('id int NOT NULL PRIMARY KEY, v int NOT NULL DEFAULT 0');
        $this->raw('INSERT INTO `'.$name.'` (id) VALUES (1), (2), (3), (4)');

        $started = microtime(true);
        $cut = false;
        try {
            // 4 lignes × 0,4 s : 1,6 s si rien ne l'arrête, le délai demandé est de 0,3 s.
            $this->query('UPDATE `'.$name.'` SET v = 1 + 0 * SLEEP(0.4)', self::write());
        } catch (QueryTimeoutException) {
            $cut = true;
        }
        $elapsed = microtime(true) - $started;

        self::assertSame($this->mariaDb(), $cut);
        if ($this->mariaDb()) {
            self::assertLessThan(1.2, $elapsed, 'MariaDB coupe l\'écriture au délai');
            self::assertSame('0', (string) $this->query('SELECT SUM(v) FROM `'.$name.'`')[0][0], 'l\'écriture coupée est annulée');
        } else {
            self::assertGreaterThan(1.3, $elapsed, 'MySQL n\'a pas de délai moteur pour une écriture');
            self::assertSame('4', (string) $this->query('SELECT SUM(v) FROM `'.$name.'`')[0][0], 'et elle est validée');
        }
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function readTimeouts(): iterable
    {
        foreach (self::targets() as $label => [$env]) {
            yield $label.' 250 ms' => [$env, 250];
            yield $label.' 1 s' => [$env, 1000];
        }
    }

    #[DataProvider('readTimeouts')]
    public function testAReadIsCutAtTheRequestedDelayOnEverySeries(string $env, int $timeoutMs): void
    {
        $this->open($env);
        $started = microtime(true);

        try {
            $this->query('SELECT SLEEP(5)', new QueryOptions(QueryMode::ReadOnly, $timeoutMs, 10));
        } catch (QueryTimeoutException) {
            // Attendu ; un SLEEP interrompu peut aussi rendre 1 sans erreur : l'essentiel est que l'attente soit coupée.
        }

        $elapsed = microtime(true) - $started;
        self::assertGreaterThan($timeoutMs / 1000 * 0.8, $elapsed);
        self::assertLessThan($timeoutMs / 1000 + 2.0, $elapsed, 'coupé au délai demandé, pas au bout des 5 s');
    }

    // Point 10 : session du client.

    #[DataProvider('targets')]
    public function testTheSessionSpeaksUtf8mb4AndAnEmojiComesBackIntact(string $env): void
    {
        $this->open($env);
        $name = $this->table('id int NOT NULL PRIMARY KEY, s varchar(50) CHARACTER SET utf8mb4 NULL', 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $session = $this->query('SELECT @@character_set_client, @@character_set_connection, @@character_set_results')[0];
        self::assertSame(['utf8mb4', 'utf8mb4', 'utf8mb4'], $session);

        $text = "caf\u{e9} \u{1F600} \u{4E2D}\u{6587} \u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
        $this->query('INSERT INTO `'.$name.'` (id, s) VALUES (1, '."'".$text."')", self::write());

        $row = $this->query('SELECT s, HEX(s), CHAR_LENGTH(s) FROM `'.$name.'` WHERE id = 1')[0];
        self::assertSame($text, $row[0]);
        self::assertSame(strtoupper(bin2hex($text)), $row[1], 'les octets stockés sont ceux envoyés');
        self::assertSame(mb_strlen($text), (int) $row[2]);
    }

    /**
     * Le fuseau de session est celui du serveur (SYSTEM) : TerrilDB ne le fixe pas. Un TIMESTAMP s'écrit et se lit dans le fuseau
     * de la session, un DATETIME n'en dépend pas ; une autre session à un autre fuseau voit le même TIMESTAMP décalé.
     */
    #[DataProvider('targets')]
    public function testTimestampFollowsTheSessionZoneAndDatetimeDoesNot(string $env): void
    {
        $this->open($env);
        $name = $this->table('id int NOT NULL PRIMARY KEY, ts timestamp NULL, dt datetime NULL');

        self::assertSame($this->query('SELECT @@global.time_zone')[0][0], $this->query('SELECT @@session.time_zone')[0][0], 'le fuseau de session est celui du serveur');

        $this->query('SET SESSION time_zone = \'+00:00\'', self::write());
        $this->query('INSERT INTO `'.$name.'` VALUES (1, \'2026-06-01 12:00:00\', \'2026-06-01 12:00:00\')', self::write());
        self::assertSame([['2026-06-01 12:00:00', '2026-06-01 12:00:00']], $this->query('SELECT ts, dt FROM `'.$name.'`'));

        $this->query('SET SESSION time_zone = \'+05:30\'', self::write());
        self::assertSame([['2026-06-01 17:30:00', '2026-06-01 12:00:00']], $this->query('SELECT ts, dt FROM `'.$name.'`'), 'TIMESTAMP décalé, DATETIME inchangé');
        self::assertSame('1780315200', (string) $this->query('SELECT UNIX_TIMESTAMP(ts) FROM `'.$name.'`')[0][0], 'le même instant');

        // Une connexion neuve revient au fuseau du serveur : la session de la précédente n'a rien changé au serveur.
        self::assertSame($this->query('SELECT @@global.time_zone')[0][0], $this->openOn()->runner()->run('SELECT @@session.time_zone', StatementKind::Read, new QueryOptions())->rows[0][0]);
    }

    /**
     * Après le retrait de NO_BACKSLASH_ESCAPES et ANSI_QUOTES, le reste du mode du serveur est gardé : écritures strictes (une valeur
     * invalide est refusée, jamais tronquée en silence). MySQL refuse en plus les dates zéro, MariaDB les accepte : mesuré.
     */
    #[DataProvider('targets')]
    public function testTheRestOfTheServerSqlModeIsKept(string $env): void
    {
        $this->open($env);
        $name = $this->table('id int NOT NULL PRIMARY KEY, n int NULL, s varchar(3) NULL, d date NULL');

        $modes = explode(',', (string) $this->query('SELECT @@session.sql_mode')[0][0]);
        $server = explode(',', (string) $this->query('SELECT @@global.sql_mode')[0][0]);
        self::assertSame(array_values(array_diff($server, ['NO_BACKSLASH_ESCAPES', 'ANSI_QUOTES'])), $modes);
        self::assertContains('STRICT_TRANS_TABLES', $modes);
        self::assertNotContains('ANSI_QUOTES', $modes);
        self::assertSame($this->mariaDb(), !\in_array('NO_ZERO_DATE', $modes, true));

        foreach (["INSERT INTO `$name` (id, n) VALUES (1, 'abc')", "INSERT INTO `$name` (id, s) VALUES (2, 'abcdef')"] as $sql) {
            try {
                $this->query($sql, self::write());
                self::fail('Le mode strict doit refuser : '.$sql);
            } catch (QueryFailedException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        self::assertSame([], $this->query('SELECT id FROM `'.$name.'`'), 'rien n\'a été écrit à moitié');

        try {
            $this->query("INSERT INTO `$name` (id, d) VALUES (3, '0000-00-00')", self::write());
            $stored = $this->query("SELECT d FROM `$name` WHERE id = 3")[0][0];
        } catch (QueryFailedException) {
            $stored = 'refused';
        }
        self::assertSame($this->mariaDb() ? '0000-00-00' : 'refused', $stored);
    }
}

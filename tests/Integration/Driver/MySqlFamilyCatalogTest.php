<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Diagnostics\Availability;
use App\Core\Diagnostics\DigestQuery;
use App\Core\Schema\TableKind;
use App\Core\Schema\TablePageRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Issue #86, points 6, 7, 9, 11 et 12 : ce que le catalogue et les instructions de MySQL 8.4/26 et MariaDB 11.8/13 ne disent pas
 * de la même façon, mesuré sur des objets jetables.
 */
#[Group('integration')]
final class MySqlFamilyCatalogTest extends TestCase
{
    use MySqlFamilyTarget;

    // Point 9 : cache de statistiques de MySQL 8 (information_schema_stats_expiry, 24 h par défaut).

    #[DataProvider('targets')]
    public function testTheEstimatesFollowTheTableInsteadOfAnOldCachedReading(string $env): void
    {
        $this->open($env);
        $name = $this->table('id int NOT NULL AUTO_INCREMENT PRIMARY KEY, v varchar(200) NOT NULL, KEY kv (v)');

        // Une première lecture met les statistiques de la table (vide) en cache sur MySQL.
        $first = $this->openOn();
        self::assertSame(0, $first->introspector()->describeTable($name)->estimatedRows);

        $this->raw(\sprintf("INSERT INTO %s (v) SELECT repeat('x', 100) FROM information_schema.COLUMNS LIMIT 2000", self::q($name)));

        // Chaque lecture ouvre sa connexion, comme une requête web : le cache du serveur ne doit pas fausser le nombre de lignes.
        $described = $this->openOn()->introspector()->describeTable($name)->estimatedRows;
        $listed = $this->summaryOf($this->openOn()->introspector()->listTables(), $name);
        $paged = $this->summaryOf($this->openOn()->introspector()->listTablesPage(new TablePageRequest(500))->tables, $name);

        foreach (['describeTable' => $described, 'listTables' => $listed, 'listTablesPage' => $paged] as $reader => $rows) {
            self::assertNotNull($rows, $reader);
            self::assertGreaterThan(1800, $rows, $reader.' lit un nombre de lignes périmé');
            self::assertLessThan(2200, $rows, $reader);
        }
    }

    // Point 6 : instructions SHOW renommées ou disparues.

    /**
     * Tout SHOW écrit dans le code de production (hors garde, qui les LIT) est exécuté tel quel sur les quatre cibles ; aucun n'est
     * une forme renommée : MASTER STATUS n'existe plus en MySQL 8.4, SLAVE STATUS non plus (BINARY LOG STATUS / REPLICA STATUS).
     */
    #[DataProvider('targets')]
    public function testEveryShowStatementOfTheCodeRunsOnEveryTarget(string $env): void
    {
        $this->open($env);
        $found = [];
        // MySqlAccountDiagnostics est écarté : « SHOW VIEW » y est le nom d'un DROIT, pas une instruction.
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../../../src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            \assert($file instanceof \SplFileInfo);
            if ('php' !== $file->getExtension() || str_contains($file->getPathname(), '/src/Core/Guard/') || str_ends_with($file->getPathname(), 'MySqlAccountDiagnostics.php') || str_contains($file->getPathname(), '/PostgreSql/') || str_contains($file->getPathname(), '/ClickHouse/')) {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (1 > preg_match_all("/['\"]\s*(SHOW\s+[A-Z ]+?)(?:\s+(?:LIKE|WHERE|FROM|IN)\b|\s*['\"\\\\]|\s*$)/m", $code, $matches)) {
                continue;
            }
            foreach ($matches[1] as $statement) {
                $found[trim($statement)] = $file->getFilename();
            }
        }

        self::assertArrayHasKey('SHOW GLOBAL STATUS', $found, 'le repérage voit les SHOW du code');
        self::assertArrayHasKey('SHOW SESSION STATUS', $found);
        foreach ($found as $statement => $where) {
            self::assertDoesNotMatchRegularExpression('/\b(?:MASTER|SLAVE|SLAVES|BINLOG)\b/i', $statement, $where.' envoie une syntaxe renommée ou absente d\'une série');
            // Aucune erreur de syntaxe : une ligne ou aucune, jamais d'exception.
            $this->query($statement);
        }
    }

    /**
     * Mesuré : MySQL 8.4/26 ont perdu MASTER STATUS et SLAVE STATUS, MariaDB les garde (et accepte aussi les nouveaux noms) ;
     * BINARY LOG STATUS et REPLICA STATUS passent partout : c'est ce qu'un code partagé peut dire.
     */
    #[DataProvider('targets')]
    public function testOnlyTheNewReplicationNamesAreSharedByBothFamilies(string $env): void
    {
        $this->open($env);
        $outcome = static function (callable $run): bool {
            try {
                $run();

                return true;
            } catch (\Throwable) {
                return false;
            }
        };

        self::assertTrue($outcome(fn () => $this->query('SHOW BINARY LOG STATUS')));
        self::assertTrue($outcome(fn () => $this->query('SHOW REPLICA STATUS')));
        self::assertSame($this->mariaDb(), $outcome(fn () => $this->query('SHOW MASTER STATUS')));
        self::assertSame($this->mariaDb(), $outcome(fn () => $this->query('SHOW SLAVE STATUS')));
    }

    // Point 7 : performance_schema éteint par défaut sur MariaDB.

    #[DataProvider('targets')]
    public function testWhatNeedsPerformanceSchemaSaysDisabledInsteadOfFailing(string $env): void
    {
        $target = $this->open($env);
        $off = $this->mariaDb();
        self::assertSame($off ? 0 : 1, (int) $this->query('SELECT @@performance_schema')[0][0], 'réglage par défaut de la série');

        $capabilities = $target->diagnostics()->capabilities();
        $statements = $target->diagnostics()->statements()->top(new DigestQuery());
        $usage = $target->introspector()->indexUsage();

        if ($off) {
            self::assertSame(Availability::Disabled, $capabilities['statements']->availability);
            self::assertSame('performance_schema_off', $capabilities['statements']->reason);
            self::assertSame('performance_schema_off', $statements->status->reason);
            self::assertSame([], $statements->digests);
            self::assertFalse($usage->available);
            self::assertSame('performance_schema_off', $usage->reason);
        } else {
            self::assertTrue($capabilities['statements']->isAvailable());
            self::assertTrue($usage->available);
        }
        // Ce qui a une autre source se lit sans performance_schema : processlist, transactions InnoDB, variables, santé.
        foreach (['sessions', 'locks', 'variables', 'health'] as $theme) {
            self::assertTrue($capabilities[$theme]->isAvailable(), $theme);
        }
        self::assertTrue($target->diagnostics()->sessions()->sessions()->status->isAvailable());
        self::assertTrue($target->diagnostics()->locks()->locks()->status->isAvailable());
    }

    #[DataProvider('targets')]
    public function testTheTableHoldersCheckSaysDisabledWithoutPerformanceSchemaAndNeverFails(string $env): void
    {
        $target = $this->open($env);
        $name = $this->table('id int NOT NULL PRIMARY KEY');

        $holders = $target->diagnostics()->locks()->tableHolders($name);

        if ($this->mariaDb()) {
            self::assertSame(Availability::Disabled, $holders->status->availability);
            self::assertSame([], $holders->holders);
        } else {
            self::assertTrue($holders->status->isAvailable());
        }
    }

    // Point 12 : tables système en Aria côté MariaDB.

    #[DataProvider('targets')]
    public function testTheSystemTablesAreReadableWhateverTheirStorageEngine(string $env): void
    {
        $this->open($env);
        $system = $this->openOn('mysql');
        $tables = $system->introspector()->listTables();
        self::assertNotEmpty($tables);

        $engines = [];
        foreach ($tables as $summary) {
            $table = $system->introspector()->describeTable($summary->name);
            self::assertNotEmpty($table->columns, $summary->name);
            if (TableKind::Table === $table->kind) {
                $engines[$table->storageEngine ?? ''] = true;
                foreach ($table->indexes as $index) {
                    // Pas de statistiques InnoDB pour Aria : taille d'index inconnue (nulle), jamais fausse ou en erreur.
                    if ('Aria' === $table->storageEngine) {
                        self::assertNull($index->sizeBytes, $summary->name.'.'.$index->name);
                    }
                }
            }
        }
        self::assertArrayHasKey($this->mariaDb() ? 'Aria' : 'InnoDB', $engines);
        // Une lecture ordinaire d'une table système passe le garde et le runner.
        self::assertGreaterThanOrEqual(0, (int) $this->query('SELECT COUNT(*) FROM mysql.help_topic')[0][0]);
    }

    /**
     * @param iterable<\App\Core\Schema\TableSummary> $summaries
     */
    private function summaryOf(iterable $summaries, string $name): ?int
    {
        foreach ($summaries as $summary) {
            if ($summary->name === $name) {
                return $summary->estimatedRows;
            }
        }

        return null;
    }
}

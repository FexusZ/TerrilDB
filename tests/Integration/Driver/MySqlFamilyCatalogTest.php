<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Diagnostics\DigestQuery;
use App\Core\Diagnostics\Availability;
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

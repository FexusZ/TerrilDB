<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\TargetConnection;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\SkippedWithMessageException;
use PHPUnit\Framework\TestCase;

/**
 * Les « bases » d'une connexion Vitess sont ses KEYSPACES (#40) : `information_schema.SCHEMATA` y rend les bases physiques du tablet
 * (`_vt`, `vt_<keyspace>_<partition>`), que vtgate ne connaît pas (les ouvrir échoue), et cache les autres keyspaces. Mesuré sur
 * vtgate (`8.0.35-Vitess`) : `SHOW DATABASES` rend `shop` et les bases système, `SCHEMATA` rend `_vt` et `vt_shop_0`. Sauté si la cible
 * `target-vitess` est éteinte (profil « vitess », voir docs/vitess-tests.md).
 */
#[Group('integration')]
final class VitessKeyspacesTest extends TestCase
{
    private ?TargetConnection $target = null;

    protected function tearDown(): void
    {
        $this->target?->close();
    }

    private function open(): TargetConnection
    {
        try {
            $parameters = TargetDatabase::parameters('TARGET_VITESS_URL');
            $this->target = (new MySqlDriver(new DbalConnector()))->connect(Engine::MySql, $parameters);
        } catch (SkippedWithMessageException $e) {
            throw $e;
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped('TARGET_VITESS_URL is unreachable (docker compose --profile vitess up -d --wait target-vitess).');
        }

        return $this->target;
    }

    public function testTheListOfDatabasesIsTheListOfKeyspacesAndNeverThePhysicalNamesOfATablet(): void
    {
        $target = $this->open();
        self::assertSame('vitess', $target->version()?->flavour->value, 'la cible doit être reconnue comme Vitess');

        $names = array_map(static fn ($d): string => $d->name, $target->introspector()->listDatabases());

        self::assertContains('shop', $names);
        self::assertNotContains('_vt', $names);
        self::assertSame([], array_values(array_filter($names, static fn (string $n): bool => 1 === preg_match('/^vt_/', $n))), 'aucun nom physique de tablet');
    }

    public function testTheSystemDatabasesAreMarkedAndTheKeyspaceIsNot(): void
    {
        $databases = $this->open()->introspector()->listDatabases();

        $byName = [];
        foreach ($databases as $database) {
            $byName[$database->name] = $database->system;
        }
        self::assertFalse($byName['shop']);
        foreach (['information_schema', 'mysql', 'sys', 'performance_schema'] as $system) {
            self::assertTrue($byName[$system] ?? true, $system.' est une base système');
        }
    }

    public function testTheListIsSortedByBytesLikeTheOtherEngines(): void
    {
        $names = array_map(static fn ($d): string => $d->name, $this->open()->introspector()->listDatabases());
        $sorted = $names;
        usort($sorted, strcmp(...));

        self::assertSame($sorted, $names);
    }
}

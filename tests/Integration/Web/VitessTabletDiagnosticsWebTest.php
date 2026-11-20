<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Integration\Driver\TargetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Sur Vitess aucun thème ne se lit à travers vtgate (il envoie chaque lecture à UN tablet, au hasard) : le tablet se CHOISIT
 * (`?tablet=<alias>`), et tout se lit alors comme sur un MySQL ordinaire. Sauté si `target-vitess` est éteinte.
 */
#[Group('integration')]
final class VitessTabletDiagnosticsWebTest extends WebApiTestCase
{
    private function saveGateway(): string
    {
        try {
            $parameters = TargetDatabase::parameters('TARGET_VITESS_URL');
        } catch (\Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }
        $created = $this->call('POST', '/web/connections', ['name' => 'Gateway', 'engine' => 'mysql', 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()]);
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return array<mixed>
     */
    private function replica(string $id): array
    {
        $body = $this->call('GET', '/web/connections/'.$id.'/diagnostics/tablets');
        if (502 === $this->statusCode()) {
            self::markTestSkipped('TARGET_VITESS_URL is unreachable (docker compose --profile vitess up -d --wait target-vitess).');
        }
        self::assertSame(200, $this->statusCode());
        foreach (self::listOf($body['tablets']) as $tablet) {
            $tablet = self::arrayOf($tablet);
            if ('REPLICA' === $tablet['type']) {
                /* @var array<string, mixed> $tablet */
                return $tablet;
            }
        }
        self::fail('target-vitess has a replica tablet');
    }

    public function testTheGatewayListsItsTabletsWithoutTheirTarget(): void
    {
        $this->seedAndLogin();
        $id = $this->saveGateway();

        $tablet = $this->replica($id);

        self::assertSame(['alias', 'cell', 'keyspace', 'shard', 'type', 'serving'], array_keys($tablet));
        self::assertIsString($tablet['alias']);
        self::assertTrue($tablet['serving']);
    }

    public function testWithoutATabletThemesAreStillUnsupportedOnTheGateway(): void
    {
        $this->seedAndLogin();
        $id = $this->saveGateway();
        $this->replica($id);

        $this->call('GET', '/web/connections/'.$id.'/diagnostics/sessions');

        self::assertSame(501, $this->statusCode());
    }

    public function testChoosingATabletServesEveryThemeFromThatTablet(): void
    {
        $this->seedAndLogin();
        $id = $this->saveGateway();
        $alias = $this->replica($id)['alias'];
        self::assertIsString($alias);

        $capabilities = self::arrayOf($this->call('GET', '/web/connections/'.$id.'/diagnostics?tablet='.$alias)['capabilities']);
        self::assertSame('available', self::arrayOf($capabilities['sessions'])['availability']);

        $sessions = $this->call('GET', '/web/connections/'.$id.'/diagnostics/sessions?tablet='.$alias);
        self::assertSame(200, $this->statusCode());
        self::assertSame('available', self::arrayOf($sessions['status'])['availability']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badAliases(): iterable
    {
        yield 'unknown tablet' => ['test-9999999999'];
        yield 'not an alias' => ['shop'];
        yield 'a target instead of an alias' => ['shop:0@primary|test-0000000001'];
    }

    #[DataProvider('badAliases')]
    public function testAnUnknownOrMalformedTabletIsRefusedBeforeAnythingIsRead(string $alias): void
    {
        $this->seedAndLogin();
        $id = $this->saveGateway();
        $this->replica($id);

        $this->call('GET', '/web/connections/'.$id.'/diagnostics/sessions?tablet='.rawurlencode($alias));

        self::assertSame(422, $this->statusCode());
    }

    public function testAPlainMySqlRefusesATabletBecauseItHasNone(): void
    {
        $this->seedAndLogin();
        $parameters = TargetDatabase::parameters('TARGET_MYSQL_URL');
        $created = $this->call('POST', '/web/connections', ['name' => 'Plain', 'engine' => 'mysql', 'host' => $parameters->host, 'port' => $parameters->port, 'database' => $parameters->database, 'username' => $parameters->username, 'password' => $parameters->password()]);
        $id = self::arrayOf($created['connection'])['id'];
        self::assertIsString($id);

        $this->call('GET', '/web/connections/'.$id.'/diagnostics/sessions?tablet=test-0000000001');

        self::assertSame(501, $this->statusCode());
    }
}

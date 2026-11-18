<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/** Durcissement du conteneur de production : système de fichiers en lecture seule, hôtes de confiance. */
final class ComposeProdTest extends TestCase
{
    /** @return array<mixed> */
    private function app(): array
    {
        $compose = Yaml::parseFile(\dirname(__DIR__).'/compose.prod.yaml');
        self::assertIsArray($compose);
        $services = $compose['services'] ?? null;
        self::assertIsArray($services);
        $app = $services['app'] ?? null;
        self::assertIsArray($app);

        return $app;
    }

    public function testApplicationFilesystemIsReadOnly(): void
    {
        self::assertTrue($this->app()['read_only'] ?? false);
    }

    public function testWritableAreasAreTmpfsOrVolumes(): void
    {
        $app = $this->app();
        $tmpfs = array_map(static fn (mixed $entry): string => explode(':', \is_string($entry) ? $entry : '')[0], (array) ($app['tmpfs'] ?? []));
        foreach (['/app/var/cache', '/app/var/log', '/tmp'] as $path) {
            self::assertContains($path, $tmpfs, $path.' doit être un tmpfs.');
        }
        self::assertContains('sessions:/data/sessions', (array) ($app['volumes'] ?? []));
    }

    /** @return array<string, mixed> */
    private function compose(): array
    {
        $compose = Yaml::parseFile(\dirname(__DIR__).'/compose.prod.yaml');
        self::assertIsArray($compose);

        return $compose;
    }

    /** @return array<mixed> */
    private function tunnel(): array
    {
        $services = $this->compose()['services'] ?? null;
        self::assertIsArray($services);
        $tunnel = $services['tunnel'] ?? null;
        self::assertIsArray($tunnel, 'Le service `tunnel` doit exister (profil facultatif).');

        return $tunnel;
    }

    public function testTheTunnelIsOptionalAndHardened(): void
    {
        $tunnel = $this->tunnel();
        self::assertSame(['tunnel'], $tunnel['profiles'] ?? null, 'Il ne démarre que si le déploiement le demande.');
        self::assertTrue($tunnel['read_only'] ?? false);
        self::assertSame(['ALL'], $tunnel['cap_drop'] ?? null);
        self::assertContains('no-new-privileges:true', (array) ($tunnel['security_opt'] ?? []));
        self::assertArrayNotHasKey('ports', $tunnel, 'Aucun port publié.');
        self::assertSame(['tunnel'], $tunnel['networks'] ?? null, 'Seul réseau : celui qui sort vers les bastions.');
        self::assertSame('33', (string) ($tunnel['build']['args']['UID'] ?? ''), 'Même uid que www-data, qui sert l\'application.');
    }

    public function testOnlyTheTunnelNetworkHasEgressAndTheInternalDatabaseStaysIsolated(): void
    {
        $networks = $this->compose()['networks'] ?? null;
        self::assertIsArray($networks);
        self::assertTrue($networks['backend']['internal'] ?? false);
        self::assertArrayHasKey('tunnel', $networks);
        self::assertNotTrue($networks['tunnel']['internal'] ?? false, 'Le tunnel doit pouvoir sortir.');
    }

    public function testTheApplicationSharesTheSocketsAndTheBastionListWithTheTunnel(): void
    {
        $app = $this->app();
        self::assertContains('tunnel-sockets:/run/tunnels', (array) ($app['volumes'] ?? []));
        self::assertNotContains('tunnel', (array) ($app['networks'] ?? []), 'L\'application ne sort pas par le réseau du tunnel.');
        self::assertArrayHasKey('TERRILDB_SSH_ALLOWED_BASTIONS', (array) ($app['environment'] ?? []));
        self::assertArrayHasKey('TERRILDB_SSH_ALLOWED_BASTIONS', (array) ($this->tunnel()['environment'] ?? []));
    }

    /** @return list<array{string, string, bool}> */
    public static function hosts(): array
    {
        return [
            ['terrildb.test', 'terrildb.test', true],
            ['terrildb.test', 'TerrilDB.test', true],
            ['terrildb.test', 'terrildbxtest', false],
            ['terrildb.test', 'terrildb.test.evil.example', false],
            ['terrildb.test', 'evil.example', false],
            ['terrildb.test', '127.0.0.1', true],
            ['terrildb.test', 'localhost', true],
            ['a.test,b.test', 'b.test', true],
        ];
    }

    #[DataProvider('hosts')]
    public function testTrustedHostsPatternAcceptsOnlyConfiguredNames(string $configured, string $host, bool $accepted): void
    {
        $process = new Process(['sh', \dirname(__DIR__).'/docker/prod/trusted-hosts.sh', $configured]);
        $process->mustRun();
        $pattern = trim($process->getOutput());

        self::assertSame($accepted, 1 === preg_match('{'.$pattern.'}i', $host), $pattern);
    }
}

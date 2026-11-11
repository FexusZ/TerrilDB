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

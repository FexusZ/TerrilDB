<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Les réseaux de TerrilDB ont une plage FIXÉE (#187) : sans cela Docker prend des `/20` dans 192.168.0.0/16 une fois 172.17-31 épuisé, et
 * l'un d'eux peut recouvrir l'adresse d'une base cible (injoignable sans que rien ne le dise). Chaque plage se règle par une variable ; sa
 * valeur par défaut reste hors des plages que Docker attribue seul.
 */
final class ComposeNetworksTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function files(): iterable
    {
        yield 'développement' => ['compose.yaml'];
        yield 'production' => ['compose.prod.yaml'];
    }

    /**
     * @return array<string, array{string, string}> réseau => [variable, plage par défaut]
     */
    private static function subnets(string $file): array
    {
        $compose = Yaml::parseFile(\dirname(__DIR__).'/'.$file);
        self::assertIsArray($compose);
        $networks = $compose['networks'] ?? null;
        self::assertIsArray($networks);

        $found = [];
        foreach ($networks as $name => $network) {
            self::assertIsString($name);
            self::assertIsArray($network, $name.' doit déclarer sa plage.');
            $ipam = $network['ipam'] ?? null;
            self::assertIsArray($ipam, $name.' n’a pas de plage fixée.');
            $config = $ipam['config'] ?? null;
            self::assertIsArray($config);
            $first = $config[0] ?? null;
            self::assertIsArray($first);
            $subnet = $first['subnet'] ?? null;
            self::assertIsString($subnet);
            if (1 !== preg_match('/^\$\{(TERRILDB_(?:[A-Z_]+_)?SUBNET):-([0-9.]+\/[0-9]+)\}$/D', $subnet, $m)) {
                self::fail($name.' : la plage doit venir d’une variable avec une valeur par défaut.');
            }
            $found[$name] = [$m[1], $m[2]];
        }

        return $found;
    }

    private static function overlapsDockerPools(string $cidr): bool
    {
        [$address, $bits] = explode('/', $cidr);
        $start = (int) ip2long($address);
        $size = 2 ** (32 - (int) $bits);
        foreach ([['172.16.0.0', 12], ['192.168.0.0', 16]] as [$pool, $poolBits]) {
            $poolStart = (int) ip2long($pool);
            $poolSize = 2 ** (32 - $poolBits);
            if ($start < $poolStart + $poolSize && $poolStart < $start + $size) {
                return true;
            }
        }

        return false;
    }

    #[DataProvider('files')]
    public function testEveryNetworkHasAFixedConfigurableRangeOutsideDockerPools(string $file): void
    {
        $subnets = self::subnets($file);
        self::assertNotSame([], $subnets);
        foreach ($subnets as $name => [$variable, $default]) {
            self::assertFalse(self::overlapsDockerPools($default), \sprintf('%s : %s tombe dans une plage que Docker attribue seul.', $name, $default));
            self::assertSame(1, preg_match('#^10\.\d+\.\d+\.0/24$#D', $default), $name);
        }
        self::assertSame(array_unique(array_column($subnets, 0)), array_column($subnets, 0), 'Une variable par réseau.');
    }

    public function testNoTwoDefaultRangesOverlapAcrossBothFiles(): void
    {
        $all = [];
        foreach (['compose.yaml', 'compose.prod.yaml'] as $file) {
            foreach (self::subnets($file) as $name => [, $default]) {
                self::assertArrayNotHasKey($default, $all, \sprintf('%s (%s) reprend la plage de %s.', $name, $file, $all[$default] ?? ''));
                $all[$default] = $file.':'.$name;
            }
        }
    }

    public function testTheDeployScriptKnowsTheProductionDefaultRange(): void
    {
        $default = self::subnets('compose.prod.yaml')['default'][1];
        $script = file_get_contents(\dirname(__DIR__).'/docker/prod/deploy.sh');
        self::assertIsString($script);
        self::assertStringContainsString('${TERRILDB_SUBNET:-'.$default.'}', $script);
        self::assertSame('TERRILDB_SUBNET', self::subnets('compose.prod.yaml')['default'][0]);
    }
}

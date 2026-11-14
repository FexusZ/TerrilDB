<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\Driver;
use App\Core\Driver\EngineCapability;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\TargetConnection;
use App\Driver\ClickHouse\ClickHouseDriver;
use App\Driver\ClickHouse\ClickHouseHttp;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use App\Driver\PostgreSql\PostgreSqlDriver;
use App\Driver\Redis\RedisConnector;
use App\Driver\Redis\RedisDriver;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Les extraits SQL de l'éditeur (`assets/snippets/sql-snippets.json`, lus aussi par le front) sont instanciés avec les valeurs
 * du schéma `shop` puis jugés par chaque cible qui les déclare supportés (moteur écrit pour lui ET capacité de la matrice) :
 * `EXPLAIN` seulement, rien ne s'exécute ni ne s'écrit. Un extrait déclaré qui ne marche pas sur une cible fait échouer le test,
 * et la déclaration du catalogue ne peut pas dériver quand une série change.
 */
#[Group('integration')]
final class SqlSnippetTest extends TestCase
{
    private ?TargetConnection $target = null;
    private ?Connection $raw = null;
    private ?ClickHouseHttp $http = null;

    protected function tearDown(): void
    {
        $this->raw?->close();
        $this->target?->close();
    }

    /**
     * @return list<array{id: string, requires: string, engines: list<string>, body: string, example: array<string, string>}>
     */
    private static function catalogue(): array
    {
        $path = \dirname(__DIR__, 3).'/assets/snippets/sql-snippets.json';
        $decoded = json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));
        /** @var list<array{id: string, requires: string, engines: list<string>, body: string, example: array<string, string>}> $decoded */

        return $decoded;
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function everySnippetOnEveryTarget(): iterable
    {
        foreach (TargetDatabase::all() as $label => [$env]) {
            foreach (self::catalogue() as $snippet) {
                yield $label.' / '.$snippet['id'] => [$env, $snippet['id']];
            }
        }
    }

    private function open(string $env): void
    {
        $engine = TargetDatabase::engine($env);
        $parameters = TargetDatabase::parameters($env);
        $driver = match ($engine) {
            Engine::MySql, Engine::MariaDb => new MySqlDriver(new DbalConnector()),
            Engine::PostgreSql => new PostgreSqlDriver(new DbalConnector()),
            Engine::ClickHouse => new ClickHouseDriver(HttpClient::create()),
            Engine::Redis => new RedisDriver(new RedisConnector()),
        };
        \assert($driver instanceof Driver);
        try {
            $this->target = $driver->connect($engine, $parameters);
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped('The target is unreachable (docker compose --profile targets up -d --wait).');
        }
        if (Engine::ClickHouse === $engine) {
            $this->http = new ClickHouseHttp(HttpClient::create(), $parameters);
        } else {
            $this->raw = (new DbalConnector())->connect($engine, $parameters);
        }
    }

    #[DataProvider('everySnippetOnEveryTarget')]
    public function testADeclaredSnippetIsAcceptedByTheTarget(string $env, string $id): void
    {
        $snippet = array_values(array_filter(self::catalogue(), static fn (array $s): bool => $s['id'] === $id))[0] ?? self::fail('Unknown snippet.');
        $this->open($env);
        \assert(null !== $this->target);
        $family = $this->target->capabilities()->family;
        $declared = \in_array($family->value, $snippet['engines'], true)
            && $this->target->capabilities()->supports(EngineCapability::from($snippet['requires']));
        if (!$declared) {
            self::markTestSkipped(\sprintf('"%s" is not declared for %s: it is never offered there.', $id, $family->value));
        }

        $sql = (string) preg_replace_callback(
            '/\$\{(\w+)\}/',
            static fn (array $m): string => $snippet['example'][$m[1]] ?? throw new \LogicException(\sprintf('No example for "%s".', $m[1])),
            $snippet['body'],
        );

        $this->explain($sql);
        $this->addToAssertionCount(1);
    }

    private function explain(string $sql): void
    {
        if (null !== $this->http) {
            $this->http->query('EXPLAIN '.$sql);

            return;
        }
        \assert(null !== $this->raw);
        $this->raw->executeQuery('EXPLAIN '.$sql)->fetchAllNumeric();
    }
}

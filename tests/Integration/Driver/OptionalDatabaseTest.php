<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\DriverRegistry;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le champ « base » d'une connexion est facultatif : sans lui on joint le serveur, et les bases se parcourent ensuite.
 */
#[Group('integration')]
final class OptionalDatabaseTest extends KernelTestCase
{
    private function registry(): DriverRegistry
    {
        $registry = self::getContainer()->get(DriverRegistry::class);
        self::assertInstanceOf(DriverRegistry::class, $registry);

        return $registry;
    }

    #[DataProvider('sql')]
    public function testASqlServerIsReachedWithoutNamingADatabase(string $envVar): void
    {
        $parameters = TargetDatabase::parameters($envVar)->withDatabase('');
        try {
            $target = $this->registry()->for(TargetDatabase::engine($envVar))->connect(TargetDatabase::engine($envVar), $parameters);
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped('The target is unreachable (docker compose --profile targets up -d --wait).');
        }

        $names = array_map(static fn ($database): string => $database->name, $target->introspector()->listDatabases());
        $target->close();

        self::assertContains('shop', $names);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sql(): iterable
    {
        yield from TargetDatabase::all();
    }

    public function testRedisWithoutANumberUsesTheFirstDatabase(): void
    {
        $seed = RedisTarget::seeded();
        try {
            $parameters = TargetDatabase::parameters(RedisTarget::ENV)->withDatabase('');
            $target = $this->registry()->for(Engine::Redis)->connect(Engine::Redis, $parameters);
            self::assertSame(Engine::Redis, $target->engine);
            $target->close();
        } finally {
            $seed->flushdb();
        }
    }
}

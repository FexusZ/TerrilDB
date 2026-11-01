<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\ConnectionParameters;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Driver\DbalConnector;
use Doctrine\DBAL\Exception\ConnectionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class DbalConnectorTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield from TargetDatabase::all();
    }

    private function skipIfUnreachable(string $envVar): void
    {
        try {
            (new DbalConnector())->connect(TargetDatabase::engine($envVar), TargetDatabase::parameters($envVar))->fetchOne('SELECT 1');
        } catch (TargetConnectionFailedException|ConnectionException) {
            self::markTestSkipped(\sprintf('%s is unreachable (docker compose --profile targets up -d --wait).', $envVar));
        }
    }

    #[DataProvider('targets')]
    public function testConnectsAndAnswersQueries(string $envVar): void
    {
        $this->skipIfUnreachable($envVar);

        $connection = (new DbalConnector())->connect(TargetDatabase::engine($envVar), TargetDatabase::parameters($envVar));

        self::assertEquals(1, $connection->fetchOne('SELECT 1'));
    }

    #[DataProvider('targets')]
    public function testAWrongPasswordIsRefusedWithoutEchoingIt(string $envVar): void
    {
        $this->skipIfUnreachable($envVar);
        $parameters = TargetDatabase::parameters($envVar)->withPassword('wrong-secret-value');

        try {
            (new DbalConnector())->connect(TargetDatabase::engine($envVar), $parameters);
            self::fail('Expected a refusal.');
        } catch (TargetConnectionFailedException $e) {
            self::assertStringNotContainsString('wrong-secret-value', $e->getMessage());
        }
    }

    #[DataProvider('targets')]
    public function testAnUnknownDatabaseIsRefused(string $envVar): void
    {
        $this->skipIfUnreachable($envVar);
        $real = TargetDatabase::parameters($envVar);
        $parameters = new ConnectionParameters($real->host, $real->port, 'no_such_database', $real->username, $real->password());

        $this->expectException(TargetConnectionFailedException::class);

        (new DbalConnector())->connect(TargetDatabase::engine($envVar), $parameters);
    }

    #[DataProvider('targets')]
    public function testAClosedPortIsRefused(string $envVar): void
    {
        $this->skipIfUnreachable($envVar);
        $real = TargetDatabase::parameters($envVar);
        $parameters = new ConnectionParameters($real->host, 1, $real->database, $real->username, $real->password());

        $this->expectException(TargetConnectionFailedException::class);

        (new DbalConnector())->connect(TargetDatabase::engine($envVar), $parameters);
    }
}

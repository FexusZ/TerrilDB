<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Core\Diagnostics\Availability;
use App\Core\Driver\Driver;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Driver\ClickHouse\ClickHouseDriver;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use App\Driver\PostgreSql\PostgreSqlDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * L'indicateur « cette connexion est chiffrée », comparé à ce que le serveur dit lui-même de sa session. Informationnel : les
 * paramètres de connexion ne changent pas. Le résultat dépend de la cible (TLS ou non), le test compare donc au serveur.
 */
#[Group('integration')]
final class EncryptionDiagnosticsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield from TargetDatabase::relational();
    }

    private function driver(Engine $engine): Driver
    {
        return match ($engine) {
            Engine::MySql, Engine::MariaDb => new MySqlDriver(new DbalConnector()),
            Engine::PostgreSql => new PostgreSqlDriver(new DbalConnector()),
            Engine::ClickHouse => new ClickHouseDriver(HttpClient::create()),
            Engine::Redis => throw new \LogicException('Redis has no driver here.'),
        };
    }

    #[DataProvider('targets')]
    public function testTheIndicatorAgreesWithWhatTheServerSaysOfTheSession(string $envVar): void
    {
        $engine = TargetDatabase::engine($envVar);
        try {
            $target = $this->driver($engine)->connect($engine, TargetDatabase::parameters($envVar));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped(\sprintf('%s is unreachable.', $envVar));
        }
        $diagnostics = $target->diagnostics()->encryption();
        $status = $diagnostics->status();
        $encryption = $diagnostics->encryption();
        $target->close();

        $own = (new DbalConnector())->connect($engine, TargetDatabase::parameters($envVar));
        if (Engine::PostgreSql === $engine) {
            $row = $own->fetchAssociative('SELECT ssl, version FROM pg_stat_ssl WHERE pid = pg_backend_pid()');
            self::assertIsArray($row);
            $expected = (bool) $row['ssl'];
            $protocol = $expected ? $row['version'] : null;
        } else {
            $version = $own->fetchAssociative("SHOW SESSION STATUS LIKE 'Ssl_version'");
            $version = \is_array($version) ? $version['Value'] : null;
            $expected = \is_string($version) && '' !== $version;
            $protocol = $expected ? $version : null;
        }
        $own->close();

        self::assertSame(Availability::Available, $status->availability);
        self::assertSame($expected, $encryption->encrypted);
        self::assertSame($protocol, $encryption->protocol);
        self::assertSame($expected, null !== $encryption->cipher);
    }

    public function testClickHouseDoesNotReportIt(): void
    {
        $engine = Engine::ClickHouse;
        try {
            $target = $this->driver($engine)->connect($engine, TargetDatabase::parameters('TARGET_CLICKHOUSE_URL'));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped('ClickHouse is unreachable.');
        }
        $status = $target->diagnostics()->capabilities()['encryption'];
        $target->close();

        self::assertSame(Availability::Unsupported, $status->availability);
    }
}

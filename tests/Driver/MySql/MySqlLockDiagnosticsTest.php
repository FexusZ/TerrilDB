<?php

declare(strict_types=1);

namespace App\Tests\Driver\MySql;

use App\Core\Connection\Engine;
use App\Core\Diagnostics\Availability;
use App\Driver\MySql\MySqlLockDiagnostics;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Les attentes de métadonnées de MySQL passent par la vue sys.schema_table_lock_waits. Un serveur sans schéma « sys » (supprimé,
 * ou fournisseur qui ne l'installe pas) n'est pas une panne des diagnostics : le thème dit ce qui lui manque.
 */
final class MySqlLockDiagnosticsTest extends TestCase
{
    private function missing(int $code): DriverException
    {
        $driver = new class('missing', $code) extends \Exception implements DriverExceptionInterface {
            public function getSQLState(): string
            {
                return '42S02';
            }
        };

        return new DriverException($driver, null);
    }

    private function connectionWithoutSys(int $code): Connection
    {
        $connection = $this->createStub(Connection::class);
        $error = $this->missing($code);
        $connection->method('fetchOne')->willReturnCallback(static function (string $sql) use ($error): int {
            if (str_contains($sql, 'sys.schema_table_lock_waits')) {
                throw $error;
            }

            return 1;
        });

        return $connection;
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function codes(): iterable
    {
        yield 'table does not exist (1146)' => [1146];
        yield 'unknown database (1049)' => [1049];
    }

    #[DataProvider('codes')]
    public function testAServerWithoutTheSysSchemaIsReportedAsSuchNotAsAFailure(int $code): void
    {
        $diagnostics = new MySqlLockDiagnostics($this->connectionWithoutSys($code), Engine::MySql);

        $status = $diagnostics->status();
        $report = $diagnostics->locks();

        self::assertSame(Availability::Disabled, $status->availability);
        self::assertSame('sys_schema_missing', $status->reason);
        self::assertSame(Availability::Disabled, $report->status->availability);
        self::assertSame([], $report->transactions);
    }

    public function testMariaDbNeverLooksAtTheSysSchema(): void
    {
        $diagnostics = new MySqlLockDiagnostics($this->connectionWithoutSys(1146), Engine::MariaDb);

        self::assertSame(Availability::Available, $diagnostics->status()->availability);
    }
}

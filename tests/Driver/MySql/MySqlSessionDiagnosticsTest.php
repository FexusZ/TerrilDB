<?php

declare(strict_types=1);

namespace App\Tests\Driver\MySql;

use App\Core\Connection\Engine;
use App\Core\Diagnostics\Availability;
use App\Driver\MySql\MySqlSessionDiagnostics;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * La source des sessions dépend du moteur : MySQL lit performance_schema.processlist (information_schema.PROCESSLIST est
 * déprécié et prend le verrou de la liste des threads), MariaDB n'a que information_schema.PROCESSLIST.
 */
final class MySqlSessionDiagnosticsTest extends TestCase
{
    /**
     * @return array{Connection, \ArrayObject<int, string>}
     */
    private function connection(int $performanceSchema, int $processPrivilege = 1): array
    {
        $listed = new \ArrayObject();
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(
            static fn (string $sql): int => str_contains($sql, '@@performance_schema') ? $performanceSchema : $processPrivilege,
        );
        $connection->method('fetchAllAssociative')->willReturnCallback(static function (string $sql) use ($listed): array {
            $listed->append($sql);

            return [];
        });

        return [$connection, $listed];
    }

    public function testMySqlReadsThePerformanceSchemaProcessList(): void
    {
        [$connection, $listed] = $this->connection(performanceSchema: 1);

        $report = (new MySqlSessionDiagnostics($connection, Engine::MySql))->sessions();

        self::assertSame(Availability::Available, $report->status->availability);
        self::assertCount(1, $listed);
        self::assertIsString($listed[0]);
        self::assertStringContainsString('performance_schema.processlist', $listed[0]);
        self::assertStringNotContainsStringIgnoringCase('information_schema.processlist', $listed[0]);
    }

    public function testMariaDbKeepsTheInformationSchemaProcessListAndNeverAsksAboutPerformanceSchema(): void
    {
        [$connection, $listed] = $this->connection(performanceSchema: 0);

        $diagnostics = new MySqlSessionDiagnostics($connection, Engine::MariaDb);
        $report = $diagnostics->sessions();

        self::assertSame(Availability::Available, $report->status->availability);
        self::assertSame(Availability::Available, $diagnostics->status()->availability);
        self::assertCount(1, $listed);
        self::assertIsString($listed[0]);
        self::assertStringContainsString('information_schema.PROCESSLIST', $listed[0]);
    }

    public function testMySqlWithThePerformanceSchemaOffSaysSoInsteadOfAnEmptyList(): void
    {
        // Désactivé, la table se lit sans erreur et rend zéro ligne : une liste vide présentée comme complète.
        [$connection, $listed] = $this->connection(performanceSchema: 0);

        $diagnostics = new MySqlSessionDiagnostics($connection, Engine::MySql);
        $status = $diagnostics->status();
        $report = $diagnostics->sessions();

        self::assertSame(Availability::Disabled, $status->availability);
        self::assertSame('performance_schema_off', $status->reason);
        self::assertSame(Availability::Disabled, $report->status->availability);
        self::assertSame([], $report->sessions);
        self::assertCount(0, $listed, 'No list is read once the source is known to be empty.');
    }

    public function testWithoutTheProcessPrivilegeTheStateIsDeniedWhateverThePerformanceSchemaSays(): void
    {
        [$connection] = $this->connection(performanceSchema: 0, processPrivilege: 0);

        $status = (new MySqlSessionDiagnostics($connection, Engine::MySql))->status();

        self::assertSame(Availability::PermissionDenied, $status->availability);
        self::assertSame('PROCESS', $status->privilege);
    }
}

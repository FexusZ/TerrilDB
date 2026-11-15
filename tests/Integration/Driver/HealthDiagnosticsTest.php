<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Core\Diagnostics\Availability;
use App\Core\Diagnostics\Health\HealthLevel;
use App\Core\Diagnostics\Health\HealthSampler;
use App\Core\Diagnostics\Health\HealthWindow;
use App\Core\Diagnostics\Health\Pause;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\TargetConnection;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use App\Driver\PostgreSql\PostgreSqlDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Santé échantillonnée sur les vrais moteurs : contrat de forme (clés présentes, valeurs plausibles), jamais des valeurs.
 */
#[Group('integration')]
final class HealthDiagnosticsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield from TargetDatabase::relational();
    }

    private function open(string $envVar): TargetConnection
    {
        $engine = TargetDatabase::engine($envVar);
        $driver = Engine::PostgreSql === $engine ? new PostgreSqlDriver(new DbalConnector()) : new MySqlDriver(new DbalConnector());
        try {
            return $driver->connect($engine, TargetDatabase::parameters($envVar));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped(\sprintf('%s is unreachable (docker compose --profile targets up -d --wait).', $envVar));
        }
    }

    #[DataProvider('targets')]
    public function testTheCapabilitiesSayHealthIsReadable(string $envVar): void
    {
        $target = $this->open($envVar);
        $status = $target->diagnostics()->capabilities()['health'];
        $target->close();

        self::assertSame(Availability::Available, $status->availability);
    }

    #[DataProvider('targets')]
    public function testASampleCarriesTheUptimeAndTheCounters(string $envVar): void
    {
        $target = $this->open($envVar);
        $sample = $target->diagnostics()->health()->sample();
        $target->close();

        self::assertGreaterThan(0, $sample->uptime);
        self::assertGreaterThan(1_700_000_000.0, $sample->takenAt);
        $expected = Engine::PostgreSql === TargetDatabase::engine($envVar)
            ? ['xact_commit', 'xact_rollback', 'blks_hit', 'blks_read', 'connections', 'max_connections']
            : ['Questions', 'Threads_connected', 'Threads_running', 'max_connections', 'Innodb_buffer_pool_read_requests'];
        foreach ($expected as $name) {
            self::assertArrayHasKey($name, $sample->counters);
            self::assertMatchesRegularExpression('/^\d+$/', $sample->counters[$name], $name);
        }
    }

    #[DataProvider('targets')]
    public function testTheCounterOfRequestsGrowsBetweenTwoSamples(string $envVar): void
    {
        $reader = $this->open($envVar);
        $load = $this->open($envVar);
        $health = $reader->diagnostics()->health();

        $before = $health->sample();
        for ($i = 0; $i < 5; ++$i) {
            $load->diagnostics()->sessions()->sessions();
        }
        $load->close(); // PostgreSQL ne publie les statistiques d'une session inactive qu'avec un retard (jusqu'à 10 s) : la fermer les publie.
        usleep(300_000);
        $after = $health->sample();
        $reader->close();

        $window = new HealthWindow($before, $after);
        self::assertNull($window->resetReason());
        $name = Engine::PostgreSql === TargetDatabase::engine($envVar) ? 'xact_rollback' : 'Questions';
        self::assertGreaterThanOrEqual(5.0, $window->delta($name));
        self::assertGreaterThan(0.0, $window->rate($name));
    }

    #[DataProvider('targets')]
    public function testTheSamplerGivesEveryIndicatorWithAPlausibleValue(string $envVar): void
    {
        $target = $this->open($envVar);
        $pause = new class implements Pause {
            public function sleep(float $seconds): void
            {
                usleep(1_100_000);
            }
        };

        $report = (new HealthSampler($pause))->measure($target->diagnostics()->health(), 1);
        $target->close();

        self::assertNull($report->discarded);
        self::assertGreaterThanOrEqual(1.0, $report->seconds);
        self::assertGreaterThanOrEqual(7, \count($report->indicators));
        foreach ($report->indicators as $indicator) {
            if (null === $indicator->value) {
                self::assertSame(HealthLevel::Unknown, $indicator->level, $indicator->key);
                continue;
            }
            self::assertGreaterThanOrEqual(0.0, $indicator->value, $indicator->key);
            if ('percent' === $indicator->unit) {
                self::assertLessThanOrEqual(100.0, $indicator->value, $indicator->key);
            }
        }
    }
}

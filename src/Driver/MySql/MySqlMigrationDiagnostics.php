<?php

declare(strict_types=1);

namespace App\Driver\MySql;

use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\MigrationDiagnostics;
use App\Core\Diagnostics\Vitess\MigrationReport;
use App\Core\Diagnostics\Vitess\VitessMigration;
use App\Driver\DbalCatalogReader;
use Doctrine\DBAL\Connection;

/**
 * `SHOW VITESS_MIGRATIONS` à travers vtgate : une ligne par migration et par shard, lue sans rien changer. Les plus récentes d'abord,
 * bornées ; une ligne dont l'UUID n'a pas la forme attendue est laissée de côté.
 */
final readonly class MySqlMigrationDiagnostics implements MigrationDiagnostics
{
    private const LIMIT = 200;

    private DbalCatalogReader $catalog;

    public function __construct(Connection $connection)
    {
        $this->catalog = DbalCatalogReader::mysql($connection);
    }

    public function status(): DiagnosticStatus
    {
        return DiagnosticStatus::available();
    }

    public function migrations(): MigrationReport
    {
        return $this->catalog->read(static function (Connection $c): MigrationReport {
            $found = [];
            foreach ($c->fetchAllAssociative('SHOW VITESS_MIGRATIONS') as $row) {
                $migration = VitessMigration::fromRow($row);
                if (null !== $migration) {
                    $found[] = $migration;
                }
            }
            usort($found, static fn (VitessMigration $a, VitessMigration $b): int => [$b->addedAt ?? '', $b->uuid] <=> [$a->addedAt ?? '', $a->uuid]);

            return new MigrationReport(DiagnosticStatus::available(), \array_slice($found, 0, self::LIMIT), \count($found) > self::LIMIT);
        });
    }
}

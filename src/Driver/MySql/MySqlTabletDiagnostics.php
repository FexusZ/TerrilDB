<?php

declare(strict_types=1);

namespace App\Driver\MySql;

use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\TabletDiagnostics;
use App\Core\Diagnostics\Vitess\VitessTablet;
use App\Driver\DbalCatalogReader;
use Doctrine\DBAL\Connection;

/**
 * `SHOW VITESS_TABLETS` : le topo que vtgate lui-même tient, lu sans rien changer. Une ligne qui n'a pas la forme d'un tablet visable
 * (alias, keyspace ou shard étranges, type sans cible comme BACKUP) est laissée de côté.
 */
final readonly class MySqlTabletDiagnostics implements TabletDiagnostics
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

    public function tablets(): array
    {
        return $this->catalog->read(static function (Connection $c): array {
            $tablets = [];
            foreach ($c->fetchAllAssociative('SHOW VITESS_TABLETS') as $row) {
                $tablet = VitessTablet::fromRow($row);
                if (null !== $tablet) {
                    $tablets[] = $tablet;
                }
                if (\count($tablets) >= self::LIMIT) {
                    break;
                }
            }

            return $tablets;
        });
    }
}

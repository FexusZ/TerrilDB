<?php

declare(strict_types=1);

namespace App\Driver\PostgreSql;

use App\Core\Diagnostics\ConnectionEncryption;
use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\EncryptionDiagnostics;
use App\Driver\DbalCatalogReader;
use App\Driver\RowReader;
use Doctrine\DBAL\Connection;

/**
 * pg_stat_ssl, ligne de la session de TerrilDB : chacun voit la sienne, aucun droit requis.
 */
final readonly class PostgreSqlEncryptionDiagnostics implements EncryptionDiagnostics
{
    private DbalCatalogReader $catalog;

    public function __construct(Connection $connection)
    {
        $this->catalog = DbalCatalogReader::postgresql($connection);
    }

    public function status(): DiagnosticStatus
    {
        return DiagnosticStatus::available();
    }

    public function encryption(): ConnectionEncryption
    {
        return $this->catalog->read(static function (Connection $c): ConnectionEncryption {
            $row = $c->fetchAssociative('SELECT ssl, version, cipher FROM pg_stat_ssl WHERE pid = pg_backend_pid()');
            if (false === $row) {
                return new ConnectionEncryption(false);
            }
            $row = new RowReader($row);

            return $row->bool('ssl') ? new ConnectionEncryption(true, $row->nullableString('version'), $row->nullableString('cipher')) : new ConnectionEncryption(false);
        });
    }
}

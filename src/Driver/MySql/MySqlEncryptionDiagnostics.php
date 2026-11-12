<?php

declare(strict_types=1);

namespace App\Driver\MySql;

use App\Core\Diagnostics\ConnectionEncryption;
use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\EncryptionDiagnostics;
use App\Driver\DbalCatalogReader;
use App\Driver\RowReader;
use Doctrine\DBAL\Connection;

/**
 * Ssl_version et Ssl_cipher de la session (MySQL et MariaDB) : vides quand la connexion n'est pas chiffrée. Aucun droit requis,
 * c'est la session de TerrilDB lui-même.
 */
final readonly class MySqlEncryptionDiagnostics implements EncryptionDiagnostics
{
    private DbalCatalogReader $catalog;

    public function __construct(Connection $connection)
    {
        $this->catalog = DbalCatalogReader::mysql($connection);
    }

    public function status(): DiagnosticStatus
    {
        return DiagnosticStatus::available();
    }

    public function encryption(): ConnectionEncryption
    {
        return $this->catalog->read(static function (Connection $c): ConnectionEncryption {
            $values = [];
            foreach ($c->fetchAllAssociative("SHOW SESSION STATUS WHERE Variable_name IN ('Ssl_version', 'Ssl_cipher')") as $row) {
                $row = new RowReader($row);
                $values[$row->string('Variable_name')] = $row->nullableString('Value');
            }
            $protocol = $values['Ssl_version'] ?? null;

            return null === $protocol ? new ConnectionEncryption(false) : new ConnectionEncryption(true, $protocol, $values['Ssl_cipher'] ?? '');
        });
    }
}

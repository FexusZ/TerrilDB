<?php

declare(strict_types=1);

namespace App\Driver;

use Doctrine\DBAL\Exception\DriverException;

/**
 * Pourquoi une lecture d'exploitation a échoué, en code stable : le détail du moteur n'est jamais montré.
 */
final class OperationsFailure
{
    /** Codes MySQL / MariaDB de droit manquant : accès refusé à la base, à la table, à la colonne, au schéma système. */
    private const MYSQL_DENIED = [1044, 1142, 1143, 1227, 1370];

    public static function reason(\Throwable $failure): string
    {
        if ($failure instanceof DriverException && ('42501' === $failure->getSQLState() || \in_array($failure->getCode(), self::MYSQL_DENIED, true))) {
            return 'permission_denied';
        }

        return 'unreadable';
    }
}

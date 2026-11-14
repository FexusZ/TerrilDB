<?php

declare(strict_types=1);

namespace App\Driver\MySql;

use App\Driver\RowReader;
use Doctrine\DBAL\Connection;

final class MySqlPrivileges
{
    /**
     * Sans PROCESS le serveur ne montre que les threads du compte, sans rien dire (ou refuse la lecture d'INNODB_TRX).
     * Un privilège reçu par un rôle n'est pas vu : l'accorder au compte lui-même.
     */
    public static function hasProcess(Connection $connection): bool
    {
        $count = $connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.USER_PRIVILEGES WHERE PRIVILEGE_TYPE = 'PROCESS' AND REPLACE(GRANTEE, '''', '') = CURRENT_USER()",
        );

        return 0 < (new RowReader(['count' => $count]))->int('count');
    }
}

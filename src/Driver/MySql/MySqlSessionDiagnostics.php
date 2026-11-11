<?php

declare(strict_types=1);

namespace App\Driver\MySql;

use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\ServerSession;
use App\Core\Diagnostics\SessionDiagnostics;
use App\Core\Diagnostics\SessionReport;
use App\Driver\DbalCatalogReader;
use App\Driver\RowReader;
use Doctrine\DBAL\Connection;

/**
 * Sessions de MySQL et MariaDB par information_schema.PROCESSLIST. Sans le privilège PROCESS le serveur ne montre que les
 * threads du compte, sans rien dire : on lit donc les privilèges d'abord et on rend l'état « droits insuffisants » plutôt
 * qu'une liste incomplète présentée comme complète. (Un privilège reçu par un rôle n'est pas vu : l'accorder au compte.).
 */
final readonly class MySqlSessionDiagnostics implements SessionDiagnostics
{
    private const LIMIT = 200;
    private const PRIVILEGE = 'PROCESS';

    private DbalCatalogReader $catalog;

    public function __construct(Connection $connection)
    {
        $this->catalog = DbalCatalogReader::mysql($connection);
    }

    public function status(): DiagnosticStatus
    {
        return $this->catalog->read(static fn (Connection $c): DiagnosticStatus => MySqlPrivileges::hasProcess($c) ? DiagnosticStatus::available() : DiagnosticStatus::denied(self::PRIVILEGE));
    }

    public function sessions(): SessionReport
    {
        return $this->catalog->read(static function (Connection $c): SessionReport {
            if (!MySqlPrivileges::hasProcess($c)) {
                return new SessionReport(DiagnosticStatus::denied(self::PRIVILEGE), [], false);
            }

            // LEFT(INFO) : le texte d'une requête géante ne traverse pas le réseau pour être raccourci ensuite.
            $rows = $c->fetchAllAssociative(
                'SELECT ID AS id, USER AS usr, HOST AS client, DB AS db, COMMAND AS command, TIME AS seconds, LEFT(INFO, 256) AS info, (ID = CONNECTION_ID()) AS me
                   FROM information_schema.PROCESSLIST
                  ORDER BY ID
                  LIMIT '.(self::LIMIT + 1),
            );

            $sessions = [];
            foreach (\array_slice($rows, 0, self::LIMIT) as $row) {
                $row = new RowReader($row);
                $client = $row->nullableString('client');
                $sessions[] = new ServerSession(
                    $row->string('id'),
                    $row->nullableString('usr'),
                    null === $client ? null : (string) preg_replace('/:\d+$/D', '', $client),
                    $row->nullableString('db'),
                    $row->nullableString('command'),
                    $row->nullableInt('seconds'),
                    $row->nullableString('info'),
                    $row->bool('me'),
                );
            }

            return new SessionReport(DiagnosticStatus::available(), $sessions, \count($rows) > self::LIMIT);
        });
    }
}

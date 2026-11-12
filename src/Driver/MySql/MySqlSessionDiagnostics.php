<?php

declare(strict_types=1);

namespace App\Driver\MySql;

use App\Core\Connection\Engine;
use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\ServerSession;
use App\Core\Diagnostics\SessionDiagnostics;
use App\Core\Diagnostics\SessionReport;
use App\Core\Guard\SqlDialect;
use App\Driver\DbalCatalogReader;
use App\Driver\RowReader;
use Doctrine\DBAL\Connection;

/**
 * Sessions de MySQL (performance_schema.processlist : information_schema.PROCESSLIST est déprécié et prend le verrou de la
 * liste des threads) et de MariaDB (information_schema.PROCESSLIST, la seule source qu'elle a). Sans le privilège PROCESS le
 * serveur ne montre que les threads du compte, sans rien dire : on lit donc les privilèges d'abord et on rend l'état « droits
 * insuffisants » plutôt qu'une liste incomplète présentée comme complète. De même, performance_schema désactivé donne une
 * table lisible mais VIDE : l'état « désactivé » plutôt qu'une liste vide. (Un privilège reçu par un rôle n'est pas vu :
 * l'accorder au compte.).
 */
final readonly class MySqlSessionDiagnostics implements SessionDiagnostics
{
    private const LIMIT = 200;
    private const PRIVILEGE = 'PROCESS';

    private DbalCatalogReader $catalog;

    public function __construct(Connection $connection, private Engine $engine)
    {
        $this->catalog = DbalCatalogReader::mysql($connection);
    }

    public function status(): DiagnosticStatus
    {
        return $this->catalog->read(fn (Connection $c): DiagnosticStatus => $this->state($c));
    }

    public function sessions(): SessionReport
    {
        return $this->catalog->read(function (Connection $c): SessionReport {
            $state = $this->state($c);
            if (!$state->isAvailable()) {
                return new SessionReport($state, [], false);
            }

            // LEFT(INFO) : le texte d'une requête géante ne traverse pas le réseau ; 4096 caractères suffisent à masquer puis raccourcir (un texte coupé au milieu d'un littéral n'est pas rendu).
            $rows = $c->fetchAllAssociative(
                'SELECT ID AS id, USER AS usr, HOST AS client, DB AS db, COMMAND AS command, TIME AS seconds, LEFT(INFO, 4096) AS info, (ID = CONNECTION_ID()) AS me
                   FROM '.$this->source().'
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
                    SqlDialect::MySql,
                );
            }

            return new SessionReport(DiagnosticStatus::available(), $sessions, \count($rows) > self::LIMIT);
        });
    }

    private function source(): string
    {
        return Engine::MariaDb === $this->engine ? 'information_schema.PROCESSLIST' : 'performance_schema.processlist';
    }

    private function state(Connection $connection): DiagnosticStatus
    {
        if (!$this->hasProcessPrivilege($connection)) {
            return DiagnosticStatus::denied(self::PRIVILEGE);
        }
        if (Engine::MariaDb !== $this->engine && 0 === (new RowReader(['on' => $connection->fetchOne('SELECT @@performance_schema')]))->int('on')) {
            return DiagnosticStatus::disabled('performance_schema_off');
        }

        return DiagnosticStatus::available();
    }

    private function hasProcessPrivilege(Connection $connection): bool
    {
        $count = $connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.USER_PRIVILEGES WHERE PRIVILEGE_TYPE = 'PROCESS' AND REPLACE(GRANTEE, '''', '') = CURRENT_USER()",
        );

        return 0 < (new RowReader(['count' => $count]))->int('count');
    }
}

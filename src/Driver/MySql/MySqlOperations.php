<?php

declare(strict_types=1);

namespace App\Driver\MySql;

use App\Core\Schema\DefinerKind;
use App\Core\Schema\DefinerObject;
use App\Core\Schema\DefinerReport;
use App\Core\Schema\TableUpkeep;
use App\Core\Schema\UpkeepReport;
use App\Driver\OperationsFailure;
use App\Driver\RowReader;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;

/**
 * Entretien et créateurs des objets de MySQL et MariaDB, lus dans information_schema (base de la connexion) et la liste des comptes.
 *
 * Place libre : DATA_FREE n'est un chiffre propre à la table que si elle a son propre fichier (InnoDB, un espace de tables par
 * table) ; dans l'espace commun ou un espace partagé, c'est la place libre de tous, identique pour chaque table : elle est alors
 * ignorée, comme pour tout moteur autre qu'InnoDB. Lire les espaces de tables demande le droit PROCESS.
 */
final readonly class MySqlOperations
{
    public function __construct(private Connection $connection)
    {
    }

    public function upkeep(): UpkeepReport
    {
        try {
            // Borné : l'audit ne juge que 300 tables, dans l'ordre des octets (comme l'introspection) ; sur un schéma de dizaines de
            // milliers de tables, lire tout, statistiques rafraîchies une à une, ne servirait à rien.
            // MySQL 8 garde ces chiffres en cache jusqu'à 24 h (information_schema_stats_expiry) : on veut ceux du moment.
            // MariaDB n'a pas cette variable et lit toujours à jour.
            try {
                $this->connection->executeStatement('SET SESSION information_schema_stats_expiry = 0');
            } catch (Exception) {
            }

            $own = $this->ownFileTables();
            $rows = $this->connection->fetchAllAssociative(
                "SELECT TABLE_NAME AS tbl, ENGINE AS engine, TABLE_ROWS AS live, DATA_LENGTH AS data, INDEX_LENGTH AS idx, DATA_FREE AS free
                   FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
                  ORDER BY BINARY TABLE_NAME
                  LIMIT 1000",
            );
        } catch (Exception $failure) {
            return UpkeepReport::unavailable(OperationsFailure::reason($failure));
        }

        $tables = [];
        foreach ($rows as $row) {
            $row = new RowReader($row);
            $name = $row->string('tbl');
            $alone = 'InnoDB' === $row->nullableString('engine') && isset($own[$name]);
            $tables[] = new TableUpkeep(
                $name,
                liveRows: $row->nullableInt('live'),
                dataBytes: $row->nullableInt('data'),
                indexBytes: $row->nullableInt('idx'),
                freeBytes: $alone ? $row->nullableInt('free') : null,
            );
        }

        return UpkeepReport::of($tables);
    }

    /**
     * Les tables de la base qui ont leur propre fichier (nom de l'espace de tables « base/table »).
     *
     * @return array<string, true>
     */
    private function ownFileTables(): array
    {
        $schema = (new RowReader(['s' => $this->connection->fetchOne('SELECT DATABASE()')]))->string('s');
        $prefix = $schema.'/';
        $mariaDb = str_contains((new RowReader(['v' => $this->connection->fetchOne('SELECT VERSION()')]))->string('v'), 'MariaDB');
        // MariaDB ne connaît que les fichiers par table dans cette liste ; MySQL y mêle les espaces partagés (SPACE_TYPE).
        $sql = $mariaDb
            ? 'SELECT NAME FROM information_schema.INNODB_SYS_TABLESPACES WHERE LEFT(NAME, ?) = ?'
            : "SELECT NAME FROM information_schema.INNODB_TABLESPACES WHERE SPACE_TYPE = 'Single' AND LEFT(NAME, ?) = ?";

        $own = [];
        foreach ($this->connection->fetchFirstColumn($sql, [mb_strlen($prefix), $prefix]) as $name) {
            $own[substr((new RowReader(['n' => $name]))->string('n'), \strlen($prefix))] = true;
        }

        return $own;
    }

    public function orphanDefiners(): DefinerReport
    {
        try {
            $accounts = [];
            foreach ($this->connection->fetchAllAssociative('SELECT User AS u, Host AS h FROM mysql.user') as $row) {
                $row = new RowReader($row);
                $accounts[$row->string('u')."\0".strtolower($row->string('h'))] = true;
            }

            $objects = [];
            // Une vue ou une routine en SQL SECURITY INVOKER n'emprunte pas l'identité de son créateur : jamais jugée. Un
            // déclencheur et un événement, eux, s'exécutent toujours sous celle de leur créateur.
            foreach ($this->connection->fetchAllAssociative("SELECT TABLE_NAME AS name, DEFINER AS definer FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND SECURITY_TYPE = 'DEFINER'") as $row) {
                $row = new RowReader($row);
                $objects[] = new DefinerObject(DefinerKind::View, $row->string('name'), null, $row->string('definer'));
            }
            foreach ($this->connection->fetchAllAssociative("SELECT ROUTINE_NAME AS name, ROUTINE_TYPE AS type, DEFINER AS definer FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND SECURITY_TYPE = 'DEFINER'") as $row) {
                $row = new RowReader($row);
                $objects[] = new DefinerObject('FUNCTION' === strtoupper($row->string('type')) ? DefinerKind::Function : DefinerKind::Procedure, $row->string('name'), null, $row->string('definer'));
            }
            foreach ($this->connection->fetchAllAssociative('SELECT TRIGGER_NAME AS name, EVENT_OBJECT_TABLE AS tbl, DEFINER AS definer FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()') as $row) {
                $row = new RowReader($row);
                $objects[] = new DefinerObject(DefinerKind::Trigger, $row->string('name'), $row->string('tbl'), $row->string('definer'));
            }
            foreach ($this->connection->fetchAllAssociative('SELECT EVENT_NAME AS name, DEFINER AS definer FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE()') as $row) {
                $row = new RowReader($row);
                $objects[] = new DefinerObject(DefinerKind::Event, $row->string('name'), null, $row->string('definer'));
            }
        } catch (Exception $failure) {
            return DefinerReport::unavailable(OperationsFailure::reason($failure));
        }

        $orphans = [];
        foreach ($objects as $object) {
            $at = strrpos($object->definer, '@');
            // Sans « @ » (un rôle sous MariaDB), le catalogue ne dit pas de quel compte il s'agit : on ne juge pas.
            if (false === $at) {
                continue;
            }
            if (!isset($accounts[substr($object->definer, 0, $at)."\0".strtolower(substr($object->definer, $at + 1))])) {
                $orphans[] = $object;
            }
        }

        return DefinerReport::of($orphans);
    }
}

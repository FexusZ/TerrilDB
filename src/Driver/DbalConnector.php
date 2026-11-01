<?php

declare(strict_types=1);

namespace App\Driver;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Ouvre une connexion DBAL vers une base cible et la vérifie tout de suite, pour qu'un refus apparaisse à
 * l'ouverture plutôt qu'à la première requête. Le délai de connexion borné évite qu'une cible muette
 * bloque un processus.
 */
final readonly class DbalConnector
{
    private const CONNECT_TIMEOUT_SECONDS = 5;

    public function connect(Engine $engine, ConnectionParameters $parameters): Connection
    {
        $options = [
            'driver' => match ($engine) {
                Engine::MySql, Engine::MariaDb => 'pdo_mysql',
                Engine::PostgreSql => 'pdo_pgsql',
            },
            'host' => $parameters->host,
            'port' => $parameters->port,
            'dbname' => $parameters->database,
            'user' => $parameters->username,
            'password' => $parameters->password(),
            'driverOptions' => [\PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS],
        ];
        if (Engine::PostgreSql !== $engine) {
            $options['charset'] = 'utf8mb4';
            // Lecture non tamponnée : une requête qui ramène des millions de lignes n'est pas chargée en mémoire.
            // Plusieurs instructions par envoi sont désactivées : défense en plus du garde.
            $options['driverOptions'][\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY] = false;
            $options['driverOptions'][\Pdo\Mysql::ATTR_MULTI_STATEMENTS] = false;
        }

        try {
            $connection = DriverManager::getConnection($options);
            $connection->executeQuery('SELECT 1');

            return $connection;
        } catch (DbalException $e) {
            throw TargetConnectionFailedException::create($e);
        }
    }
}

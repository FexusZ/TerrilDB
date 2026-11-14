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
    // Sans base nommée, PostgreSQL chercherait une base du nom de l'utilisateur : on prend celle que tout serveur possède.
    private const POSTGRES_MAINTENANCE_DATABASE = 'postgres';

    /**
     * @return array{driver: 'pdo_mysql'|'pdo_pgsql', user: string, password: string, driverOptions: array<int, mixed>, host?: string, port?: int, unix_socket?: string, dbname?: string, charset?: string}
     */
    public function options(Engine $engine, ConnectionParameters $parameters): array
    {
        if (null !== $parameters->transport && null === $parameters->socketPath()) {
            // Un rebond sans tunnel ouvert : joindre la base en direct contournerait le rebond voulu.
            throw new \LogicException('A connection through a jump must be opened through its tunnel.');
        }
        $options = [
            'driver' => match ($engine) {
                Engine::MySql, Engine::MariaDb => 'pdo_mysql',
                Engine::PostgreSql => 'pdo_pgsql',
                Engine::ClickHouse, Engine::Redis => throw new \InvalidArgumentException('This engine is not reached through DBAL.'),
            },
            'user' => $parameters->username,
            'password' => $parameters->password(),
            'driverOptions' => [\PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS],
        ];
        $socket = $parameters->socketPath();
        if (null === $socket) {
            $options['host'] = $parameters->address();
            $options['port'] = $parameters->port;
        } elseif (Engine::PostgreSql === $engine) {
            // libpq cherche `.s.PGSQL.<port>` dans le dossier donné comme hôte.
            $options['host'] = \dirname($socket);
            $options['port'] = $parameters->port;
        } else {
            $options['unix_socket'] = $socket;
        }
        if ('' !== $parameters->database) {
            $options['dbname'] = $parameters->database;
        } elseif (Engine::PostgreSql === $engine) {
            $options['dbname'] = self::POSTGRES_MAINTENANCE_DATABASE;
        }
        if (Engine::PostgreSql !== $engine) {
            $options['charset'] = 'utf8mb4';
            // Lecture non tamponnée : une requête qui ramène des millions de lignes n'est pas chargée en mémoire.
            // Plusieurs instructions par envoi sont désactivées : défense en plus du garde.
            $options['driverOptions'][\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY] = false;
            $options['driverOptions'][\Pdo\Mysql::ATTR_MULTI_STATEMENTS] = false;
        }

        return $options;
    }

    public function connect(Engine $engine, ConnectionParameters $parameters): Connection
    {
        $options = $this->options($engine, $parameters);

        try {
            $connection = DriverManager::getConnection($options);
            $connection->executeQuery('SELECT 1');
            if (Engine::PostgreSql === $engine && null !== $parameters->schema) {
                // quote_ident : le nom est une donnée, jamais du SQL.
                $connection->executeQuery("SELECT set_config('search_path', quote_ident(?), false)", [$parameters->schema]);
            }

            return $connection;
        } catch (DbalException $e) {
            throw TargetConnectionFailedException::create($e);
        }
    }
}

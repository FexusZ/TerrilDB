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

    public function connect(Engine $engine, ConnectionParameters $parameters): Connection
    {
        $options = [
            'driver' => match ($engine) {
                Engine::MySql, Engine::MariaDb => 'pdo_mysql',
                Engine::PostgreSql => 'pdo_pgsql',
                Engine::ClickHouse, Engine::Redis => throw new \InvalidArgumentException('This engine is not reached through DBAL.'),
            },
            'host' => $parameters->address(),
            'port' => $parameters->port,
            'user' => $parameters->username,
            'password' => $parameters->password(),
            'driverOptions' => [\PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS],
        ];
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

        try {
            $connection = DriverManager::getConnection($options);
            $connection->executeQuery('SELECT 1');
            if (Engine::PostgreSql !== $engine) {
                $this->readTextLikeTheGuard($connection);
            }
            if (Engine::PostgreSql === $engine) {
                // Le garde lit '…' sans échappement par antislash : la session le fait aussi, quel que soit le réglage du rôle ou de la base.
                $connection->executeQuery("SELECT set_config('standard_conforming_strings', 'on', false)");
            }
            if (Engine::PostgreSql === $engine && null !== $parameters->schema) {
                // quote_ident : le nom est une donnée, jamais du SQL.
                $connection->executeQuery("SELECT set_config('search_path', quote_ident(?), false)", [$parameters->schema]);
            }

            return $connection;
        } catch (DbalException $e) {
            throw TargetConnectionFailedException::create($e);
        }
    }

    /**
     * Le garde lit les chaînes et identifiants comme MySQL par défaut (antislash = échappement, guillemet double = chaîne). Un
     * serveur réglé sur NO_BACKSLASH_ESCAPES ou ANSI_QUOTES lirait autrement le même texte et pourrait cacher au garde une
     * construction refusée : ces deux modes sont retirés de LA SESSION (les autres sont gardés).
     *
     * @throws DbalException
     */
    private function readTextLikeTheGuard(Connection $connection): void
    {
        $modes = explode(',', \is_string($mode = $connection->fetchOne('SELECT @@SESSION.sql_mode')) ? $mode : '');
        $kept = array_values(array_filter($modes, static fn (string $mode): bool => !\in_array(strtoupper(trim($mode)), ['NO_BACKSLASH_ESCAPES', 'ANSI_QUOTES'], true)));
        if (\count($kept) !== \count($modes)) {
            $connection->executeStatement(\sprintf('SET SESSION sql_mode = %s', $connection->quote(implode(',', $kept))));
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Driver\ClickHouse\ClickHouseHttp;
use App\Driver\DbalConnector;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Une base de test dédiée avec plus de 200 tables (dont des noms pièges), créée puis supprimée par les tests de
 * pagination : la base « shop » partagée n'est jamais touchée.
 */
final class ManyTablesFixture
{
    public const DATABASE = 'tdb_page_test';
    public const SEQUENCE = 230;
    /** Noms qui éprouvent le tri à l'octet et la recherche littérale (« _ » et « % » ne sont pas des jokers). */
    public const SPECIAL = ['B_up', 'a_low', 'zz_a_b', 'zz_aXb', 'zz_a%b'];

    /**
     * @return list<string> tous les noms créés, triés à l'octet
     */
    public static function names(): array
    {
        $names = self::SPECIAL;
        for ($i = 1; $i <= self::SEQUENCE; ++$i) {
            $names[] = \sprintf('t_%03d', $i);
        }
        usort($names, strcmp(...));

        return $names;
    }

    public static function create(string $envVar): void
    {
        self::drop($envVar);
        $engine = TargetDatabase::engine($envVar);
        $parameters = TargetDatabase::parameters($envVar);

        if (Engine::ClickHouse === $engine) {
            $http = new ClickHouseHttp(HttpClient::create(), $parameters);
            $http->query('CREATE DATABASE '.self::DATABASE);
            foreach (self::names() as $name) {
                $http->query('CREATE TABLE '.self::DATABASE.'.`'.$name.'` (id UInt8) ENGINE = Memory');
            }

            return;
        }

        $admin = (new DbalConnector())->connect($engine, $parameters);
        $admin->executeStatement('CREATE DATABASE '.self::DATABASE);
        $admin->close();

        $connection = (new DbalConnector())->connect($engine, $parameters->withDatabase(self::DATABASE));
        $quote = Engine::PostgreSql === $engine ? '"' : '`';
        foreach (self::names() as $name) {
            $connection->executeStatement('CREATE TABLE '.$quote.$name.$quote.' (id INT)');
        }
        $connection->close();
    }

    public static function drop(string $envVar): void
    {
        $engine = TargetDatabase::engine($envVar);
        $parameters = TargetDatabase::parameters($envVar);

        if (Engine::ClickHouse === $engine) {
            (new ClickHouseHttp(HttpClient::create(), $parameters))->query('DROP DATABASE IF EXISTS '.self::DATABASE);

            return;
        }

        $admin = (new DbalConnector())->connect($engine, $parameters);
        $admin->executeStatement(Engine::PostgreSql === $engine ? 'DROP DATABASE IF EXISTS '.self::DATABASE.' WITH (FORCE)' : 'DROP DATABASE IF EXISTS '.self::DATABASE);
        $admin->close();
    }
}

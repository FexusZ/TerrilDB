<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use App\Core\Connection\Engine;
use App\Core\Schema\TableDefinition;
use App\Driver\ClickHouse\ClickHouseHttp;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlIntrospector;
use App\Driver\PostgreSql\PostgreSqlIntrospector;
use App\Tests\Integration\Driver\TargetDatabase;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Une connexion de jeu de données, la même pour les six cibles SQL (DBAL, ou l'interface HTTP de ClickHouse) : exécuter, lire une
 * valeur, lire des lignes. Les jeux de données créent des objets jetables nommés `tdb_ds_<hex>…` : `leftovers()` dit ce qui
 * reste sur une cible, pour que les tests vérifient qu'ils ne laissent rien.
 */
final class DatasetSession
{
    public const PREFIX = 'tdb_ds_';

    private ?Connection $dbal = null;
    private ?ClickHouseHttp $http = null;

    private function __construct(public readonly string $env, public readonly Engine $engine)
    {
        $parameters = TargetDatabase::parameters($env);
        if (Engine::ClickHouse === $engine) {
            $this->http = new ClickHouseHttp(HttpClient::create(), $parameters);
        } else {
            $this->dbal = (new DbalConnector())->connect($engine, $parameters);
        }
    }

    public static function open(string $env): self
    {
        return new self($env, TargetDatabase::engine($env));
    }

    /** Un nom d'objet jetable : préfixe, huit hexadécimaux, puis un suffixe lisible. */
    public static function name(string $suffix = ''): string
    {
        return self::PREFIX.bin2hex(random_bytes(4)).('' === $suffix ? '' : '_'.$suffix);
    }

    public function connection(): Connection
    {
        return $this->dbal ?? throw new \LogicException('ClickHouse has no DBAL connection.');
    }

    /** Exécute et rend le nombre de lignes touchées (0 sur ClickHouse, qui ne le dit pas pour une écriture asynchrone). */
    public function exec(string $sql): int
    {
        if (null !== $this->http) {
            $this->http->query($sql, timeoutSeconds: 120.0);

            return 0;
        }

        return (int) $this->connection()->executeStatement($sql);
    }

    public function describe(string $table): TableDefinition
    {
        $introspector = Engine::PostgreSql === $this->engine
            ? new PostgreSqlIntrospector($this->connection())
            : new MySqlIntrospector($this->connection());

        return $introspector->describeTable($table);
    }

    /**
     * Les noms des index d'une table autres que la clé primaire.
     *
     * @return list<string>
     */
    public function secondaryIndexes(string $table): array
    {
        $names = [];
        foreach ($this->describe($table)->indexes as $index) {
            if (!$index->primary) {
                $names[] = $index->name;
            }
        }

        return $names;
    }

    /**
     * Insère `$count` lignes numérotées de 1 à `$count` : `$values` est la liste des expressions en fonction de `n`. CTE récursive
     * (MySQL, MariaDB : profondeur levée pour la session) ou `generate_series` (PostgreSQL).
     */
    public function insertSeries(string $table, string $columns, string $values, int $count): void
    {
        if (Engine::PostgreSql === $this->engine) {
            $this->exec(\sprintf('INSERT INTO %s (%s) SELECT %s FROM generate_series(1, %d) AS g(n)', $table, $columns, $values, $count));

            return;
        }
        $this->exec(Engine::MariaDb === $this->engine ? 'SET SESSION max_recursive_iterations = 1000000' : 'SET SESSION cte_max_recursion_depth = 1000000');
        $this->exec(\sprintf('INSERT INTO %s (%s) WITH RECURSIVE s (n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM s WHERE n < %d) SELECT %s FROM s', $table, $columns, $count, $values));
    }

    /** @return list<list<mixed>> */
    public function rows(string $sql): array
    {
        if (null !== $this->http) {
            return $this->http->query($sql, timeoutSeconds: 120.0)->rows;
        }

        return $this->connection()->fetchAllNumeric($sql);
    }

    /** Première colonne de la première ligne, en texte. */
    public function scalar(string $sql): string
    {
        $value = $this->rows($sql)[0][0] ?? null;

        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * Les tables, vues, fonctions et déclencheurs jetables encore présents dans la base de la connexion.
     *
     * @return list<string>
     */
    public function leftovers(): array
    {
        $sql = match ($this->engine) {
            Engine::ClickHouse => "SELECT name FROM system.tables WHERE database = currentDatabase() AND left(name, 7) = 'tdb_ds_' ORDER BY name",
            Engine::PostgreSql => "SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND left(table_name, 7) = 'tdb_ds_'
                UNION ALL SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = current_schema() AND left(p.proname, 7) = 'tdb_ds_'
                ORDER BY 1",
            default => "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LEFT(TABLE_NAME, 7) = 'tdb_ds_'
                UNION ALL SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND LEFT(ROUTINE_NAME, 7) = 'tdb_ds_'
                UNION ALL SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND LEFT(TRIGGER_NAME, 7) = 'tdb_ds_'
                ORDER BY 1",
        };

        return array_map(static fn (array $row): string => \is_scalar($row[0]) ? (string) $row[0] : '', $this->rows($sql));
    }

    public function close(): void
    {
        $this->dbal?->close();
        $this->dbal = null;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use App\Core\Connection\Engine;
use App\Driver\MySql\MySqlIntrospector;
use App\Driver\PostgreSql\PostgreSqlIntrospector;

/**
 * La grande table déséquilibrée : 200 000 lignes, un état à trois valeurs dont une rare (5 %), 365 dates, un renvoi vers une petite
 * table de 2 000 lignes et AUCUN index secondaire. Tout est calculé (n % 20, n * 7919 % 2000…), rien n'est aléatoire. Le renvoi
 * n'est pas une clé étrangère déclarée : MySQL lui créerait un index, et le jeu sert justement à montrer ce que l'index change.
 * Générée en une instruction par une CTE récursive (MySQL, MariaDB), `generate_series` (PostgreSQL) ou `numbers()` (ClickHouse).
 * Toujours finir par `drop()` dans un `finally`.
 */
final class SkewedTable
{
    public const ROWS = 200_000;
    public const LOOKUP_ROWS = 2000;

    public readonly string $name;
    public readonly string $lookup;
    public float $loadSeconds = 0.0;

    private function __construct(private readonly DatasetSession $session, private readonly int $rows)
    {
        $base = DatasetSession::name();
        $this->name = $base.'_big';
        $this->lookup = $base.'_small';
    }

    /** Crée et remplit la table ; un échec en route supprime ce qui a été créé. */
    public static function create(string $env, int $rows = self::ROWS): self
    {
        $table = new self(DatasetSession::open($env), $rows);
        $started = microtime(true);
        try {
            $table->build();
        } catch (\Throwable $e) {
            $table->drop();
            throw $e;
        }
        $table->loadSeconds = microtime(true) - $started;

        return $table;
    }

    public function exec(string $sql): void
    {
        $this->session->exec($sql);
    }

    public function scalar(string $sql): string
    {
        return $this->session->scalar($sql);
    }

    /** @return list<list<mixed>> */
    public function rows(string $sql): array
    {
        return $this->session->rows($sql);
    }

    /** Les statistiques du moteur au jour (sans elles, un plan juge sur une table vide). */
    public function analyze(): void
    {
        match ($this->session->engine) {
            Engine::PostgreSql => $this->exec('ANALYZE '.$this->name),
            Engine::ClickHouse => null,
            default => $this->session->connection()->fetchAllAssociative('ANALYZE TABLE '.$this->name),
        };
    }

    /**
     * Les index autres que la clé primaire. ClickHouse n'a que sa clé de tri : rien à rendre.
     *
     * @return list<string>
     */
    public function secondaryIndexes(): array
    {
        $introspector = match ($this->session->engine) {
            Engine::ClickHouse => null,
            Engine::PostgreSql => new PostgreSqlIntrospector($this->session->connection()),
            default => new MySqlIntrospector($this->session->connection()),
        };
        if (null === $introspector) {
            return [];
        }
        $names = [];
        foreach ($introspector->describeTable($this->name)->indexes as $index) {
            if (!$index->primary) {
                $names[] = $index->name;
            }
        }

        return $names;
    }

    /** Supprime les deux tables ; sans effet si elles n'existent plus. */
    public function drop(): void
    {
        try {
            $this->exec('DROP TABLE IF EXISTS '.$this->name);
            $this->exec('DROP TABLE IF EXISTS '.$this->lookup);
        } finally {
            $this->session->close();
        }
    }

    /**
     * @return list<string> les tables jetables restantes sur la cible (ouvre sa propre connexion)
     */
    public static function leftovers(string $env): array
    {
        $session = DatasetSession::open($env);
        try {
            return $session->leftovers();
        } finally {
            $session->close();
        }
    }

    private function build(): void
    {
        $engine = $this->session->engine;
        $n = 'n';
        // Mêmes valeurs sur tous les moteurs : 5 % « flagged », 35 % « pending », 60 % « done ».
        $status = "CASE WHEN {$n} % 20 = 0 THEN 'flagged' WHEN {$n} % 20 <= 7 THEN 'pending' ELSE 'done' END";
        $lookupId = "1 + ({$n} * 7919) % ".self::LOOKUP_ROWS;
        $amount = "{$n} % 1000";

        if (Engine::ClickHouse === $engine) {
            $this->exec(\sprintf('CREATE TABLE %s (id UInt32, lookup_id UInt32, status String, created_on Date, amount UInt32) ENGINE = MergeTree ORDER BY id', $this->name));
            $this->exec(\sprintf('CREATE TABLE %s (id UInt32, label String) ENGINE = MergeTree ORDER BY id', $this->lookup));
            $this->exec(\sprintf("INSERT INTO %s SELECT n, %s, %s, toDate('2025-01-01') + (n %% 365), %s FROM (SELECT number + 1 AS n FROM numbers(%d))", $this->name, $lookupId, $status, $amount, $this->rows));
            $this->exec(\sprintf("INSERT INTO %s SELECT n, concat('lookup-', toString(n)) FROM (SELECT number + 1 AS n FROM numbers(%d))", $this->lookup, self::LOOKUP_ROWS));

            return;
        }

        $this->exec(\sprintf('CREATE TABLE %s (id INT NOT NULL PRIMARY KEY, label VARCHAR(20) NOT NULL)', $this->lookup));
        $this->exec(\sprintf('CREATE TABLE %s (id INT NOT NULL PRIMARY KEY, lookup_id INT NOT NULL, status VARCHAR(10) NOT NULL, created_on DATE NOT NULL, amount INT NOT NULL)', $this->name));

        if (Engine::PostgreSql === $engine) {
            $this->exec(\sprintf("INSERT INTO %s SELECT n, 1 + (n::bigint * 7919) %% %d, %s, DATE '2025-01-01' + (n %% 365), %s FROM generate_series(1, %d) AS g(n)", $this->name, self::LOOKUP_ROWS, $status, $amount, $this->rows));
            $this->exec(\sprintf("INSERT INTO %s SELECT n, 'lookup-' || n FROM generate_series(1, %d) AS g(n)", $this->lookup, self::LOOKUP_ROWS));
        } else {
            // La profondeur d'une CTE récursive est bornée par défaut (1000 ou 4096) : on la lève pour la session.
            $this->exec(Engine::MariaDb === $engine ? 'SET SESSION max_recursive_iterations = 1000000' : 'SET SESSION cte_max_recursion_depth = 1000000');
            $this->exec(\sprintf("INSERT INTO %s (id, lookup_id, status, created_on, amount) WITH RECURSIVE s (n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM s WHERE n < %d) SELECT n, %s, %s, DATE_ADD('2025-01-01', INTERVAL (n %% 365) DAY), %s FROM s", $this->name, $this->rows, $lookupId, $status, $amount));
            $this->exec(\sprintf("INSERT INTO %s (id, label) WITH RECURSIVE s (n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM s WHERE n < %d) SELECT n, CONCAT('lookup-', n) FROM s", $this->lookup, self::LOOKUP_ROWS));
        }
        $this->analyze();
    }
}

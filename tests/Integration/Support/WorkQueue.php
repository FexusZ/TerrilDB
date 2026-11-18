<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use App\Core\Schema\ColumnDefinition;
use Doctrine\DBAL\Connection;

/**
 * La file de tâches et le stock des scénarios de concurrence : 1 000 tâches « todo » (index sur l'état) et 50 produits de 100
 * unités, avec une colonne de version pour le verrou optimiste. Valeurs calculées, jamais aléatoires. Toujours `drop()` dans un
 * `finally`.
 */
final class WorkQueue
{
    public const TASKS = 1000;
    public const PRODUCTS = 50;

    public readonly string $tasks;
    public readonly string $stock;

    private function __construct(private readonly DatasetSession $session)
    {
        $base = DatasetSession::name();
        $this->tasks = $base.'_tasks';
        $this->stock = $base.'_stock';
    }

    public static function create(string $env): self
    {
        $queue = new self(DatasetSession::open($env));
        try {
            $queue->build();
        } catch (\Throwable $e) {
            $queue->drop();
            throw $e;
        }

        return $queue;
    }

    public function exec(string $sql): int
    {
        return $this->session->exec($sql);
    }

    public function scalar(string $sql): string
    {
        return $this->session->scalar($sql);
    }

    public function connection(): Connection
    {
        return $this->session->connection();
    }

    /** @return list<string> */
    public function secondaryIndexes(): array
    {
        return $this->session->secondaryIndexes($this->tasks);
    }

    /** Une colonne de la table des tâches, nulle si elle n'existe pas (relue au catalogue à chaque appel). */
    public function column(string $name): ?ColumnDefinition
    {
        return $this->session->describe($this->tasks)->column($name);
    }

    public function drop(): void
    {
        try {
            $this->exec('DROP TABLE IF EXISTS '.$this->tasks);
            $this->exec('DROP TABLE IF EXISTS '.$this->stock);
        } finally {
            $this->session->close();
        }
    }

    private function build(): void
    {
        $s = $this->session;
        $s->exec(\sprintf('CREATE TABLE %s (id INT NOT NULL PRIMARY KEY, state VARCHAR(10) NOT NULL, version INT NOT NULL DEFAULT 0, payload VARCHAR(30) NOT NULL)', $this->tasks));
        $s->exec(\sprintf('CREATE INDEX %1$s_state ON %1$s (state)', $this->tasks));
        $s->exec(\sprintf('CREATE TABLE %s (id INT NOT NULL PRIMARY KEY, name VARCHAR(30) NOT NULL, qty INT NOT NULL, version INT NOT NULL DEFAULT 0)', $this->stock));
        $s->insertSeries($this->tasks, 'id, state, version, payload', "n, 'todo', 0, CONCAT('task-', n)", self::TASKS);
        $s->insertSeries($this->stock, 'id, name, qty, version', "n, CONCAT('product-', n), 100, 0", self::PRODUCTS);
    }
}

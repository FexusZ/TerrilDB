<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use App\Core\Connection\Engine;

/**
 * Un déclencheur et une fonction jetables. Le déclencheur recopie chaque ligne ajoutée à `source` vers `sync` (identifiant,
 * opération, quantité). La fonction, déterministe, rend selon son argument 0 à 3 : le plus grand entier de 64 bits, ce nombre
 * plus un, le plus petit moins un, puis 38 chiffres 9 : des valeurs que la grille et l'export doivent écrire sans les arrondir.
 * Toujours `drop()` dans un `finally`.
 */
final class ProgrammableObjects
{
    public readonly string $source;
    public readonly string $sync;
    public readonly string $function;

    private function __construct(private readonly DatasetSession $session)
    {
        $base = DatasetSession::name();
        $this->source = $base.'_src';
        $this->sync = $base.'_sync';
        $this->function = $base.'_edge';
    }

    public static function create(string $env): self
    {
        $objects = new self(DatasetSession::open($env));
        try {
            $objects->build();
        } catch (\Throwable $e) {
            $objects->drop();
            throw $e;
        }

        return $objects;
    }

    public function exec(string $sql): int
    {
        return $this->session->exec($sql);
    }

    public function scalar(string $sql): string
    {
        return $this->session->scalar($sql);
    }

    public function triggerCount(): ?int
    {
        return $this->session->describe($this->source)->triggerCount;
    }

    public function drop(): void
    {
        try {
            $postgres = Engine::PostgreSql === $this->session->engine;
            $this->exec('DROP TABLE IF EXISTS '.$this->source.($postgres ? ' CASCADE' : ''));
            $this->exec('DROP TABLE IF EXISTS '.$this->sync);
            $this->exec($postgres ? \sprintf('DROP FUNCTION IF EXISTS %s(int)', $this->function) : 'DROP FUNCTION IF EXISTS '.$this->function);
            if ($postgres) {
                $this->exec(\sprintf('DROP FUNCTION IF EXISTS %s_copy()', $this->source));
            }
        } finally {
            $this->session->close();
        }
    }

    private function build(): void
    {
        $s = $this->session;
        $s->exec(\sprintf('CREATE TABLE %s (id INT NOT NULL PRIMARY KEY, qty INT NOT NULL)', $this->source));
        $s->exec(\sprintf('CREATE TABLE %s (src_id INT NOT NULL, op VARCHAR(10) NOT NULL, qty INT NOT NULL)', $this->sync));

        $cases = 'CASE k WHEN 0 THEN 9223372036854775807 WHEN 1 THEN 9223372036854775808 WHEN 2 THEN -9223372036854775809 ELSE 99999999999999999999999999999999999999 END';
        if (Engine::PostgreSql === $s->engine) {
            $s->exec(\sprintf("CREATE FUNCTION %s_copy() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN INSERT INTO %s VALUES (NEW.id, 'insert', NEW.qty); RETURN NEW; END \$\$", $this->source, $this->sync));
            $s->exec(\sprintf('CREATE TRIGGER %1$s_copy AFTER INSERT ON %1$s FOR EACH ROW EXECUTE FUNCTION %1$s_copy()', $this->source));
            $s->exec(\sprintf('CREATE FUNCTION %s(k int) RETURNS numeric LANGUAGE sql IMMUTABLE AS $$ SELECT %s $$', $this->function, $cases));

            return;
        }
        $s->exec(\sprintf("CREATE TRIGGER %1\$s_copy AFTER INSERT ON %1\$s FOR EACH ROW INSERT INTO %2\$s VALUES (NEW.id, 'insert', NEW.qty)", $this->source, $this->sync));
        $s->exec(\sprintf('CREATE FUNCTION %s(k INT) RETURNS DECIMAL(38,0) DETERMINISTIC RETURN %s', $this->function, $cases));
    }
}

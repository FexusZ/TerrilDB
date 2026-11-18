<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use App\Core\Connection\Engine;

/**
 * Un schéma « fautif » d'une quinzaine de tables jetables (`tdb_ds_<hex>_<nom>`), planté dans la base de la cible pour que
 * l'audit ait de quoi signaler. Chaque table porte UN défaut (ou aucun : les témoins). Toujours finir par `drop()` dans un
 * `finally`. Une clé étrangère écrite en abrégé dans la colonne (`REFERENCES`) est
 * créée par les versions récentes de tous les moteurs de test : le défaut planté (`child_abbrev`) est donc une colonne qui
 * porte le nom d'une clé étrangère sans qu'aucune soit déclarée.
 *
 * Défauts jugés par une règle de l'audit : `junction` et `copy` (sans clé primaire), `uuid_key` (UUID en texte comme clé),
 * `fk_noindex` (clé étrangère sans index, PostgreSQL seulement : MySQL et MariaDB l'indexent d'office), `dup_idx` et `prefix_idx` (index en double, index préfixe d'un autre), `price_float`
 * (somme en flottant), `counter` (compteur proche de sa limite), `mismatch` (type de clé étrangère à convertir, PostgreSQL).
 * Sans règle, seulement visibles de l'introspection : `child_abbrev`, `twin_a`/`twin_b`, `myisam`, `collate_a`/`collate_b`.
 * Témoins sans défaut : `parent`, `child_ok`.
 */
final class FaultySchema
{
    public readonly string $base;

    private function __construct(public readonly DatasetSession $session)
    {
        $this->base = DatasetSession::name();
    }

    public static function create(string $env): self
    {
        $schema = new self(DatasetSession::open($env));
        try {
            $schema->build();
        } catch (\Throwable $e) {
            $schema->drop();
            throw $e;
        }

        return $schema;
    }

    public function table(string $suffix): string
    {
        return $this->base.'_'.$suffix;
    }

    /** Supprime toutes les tables du schéma, quel que soit l'ordre des clés étrangères ; sans effet si elles n'existent plus. */
    public function drop(): void
    {
        try {
            $postgres = Engine::PostgreSql === $this->session->engine;
            if (!$postgres) {
                $this->session->exec('SET foreign_key_checks = 0');
            }
            foreach ($this->session->leftovers() as $name) {
                if (str_starts_with($name, $this->base)) {
                    $this->session->exec('DROP TABLE IF EXISTS '.$name.($postgres ? ' CASCADE' : ''));
                }
            }
        } finally {
            $this->session->close();
        }
    }

    private function build(): void
    {
        $s = $this->session;
        $postgres = Engine::PostgreSql === $s->engine;
        $t = $this->table(...);

        $s->exec(\sprintf('CREATE TABLE %s (id INT NOT NULL PRIMARY KEY, name VARCHAR(40) NOT NULL)', $t('parent')));
        $s->exec(\sprintf('INSERT INTO %s VALUES (1, \'a\'), (2, \'b\')', $t('parent')));

        // Témoin : clé étrangère déclarée ET indexée.
        $s->exec(\sprintf('CREATE TABLE %s (id INT NOT NULL PRIMARY KEY, parent_id INT NOT NULL, CONSTRAINT %1$s_fk FOREIGN KEY (parent_id) REFERENCES %s (id))', $t('child_ok'), $t('parent')));
        $s->exec(\sprintf('CREATE INDEX %1$s_parent ON %1$s (parent_id)', $t('child_ok')));

        // Clé étrangère seulement sous-entendue par le nom de la colonne : rien n'est déclaré.
        $s->exec(\sprintf('CREATE TABLE %s (id INT NOT NULL PRIMARY KEY, parent_id INT NOT NULL)', $t('child_abbrev')));
        $s->exec(\sprintf('CREATE INDEX %1$s_parent ON %1$s (parent_id)', $t('child_abbrev')));

        // Clé étrangère déclarée sans index : PostgreSQL seulement, MySQL et MariaDB en créent un d'office et refusent de le retirer (1553).
        $s->exec(\sprintf('CREATE TABLE %s (id INT NOT NULL PRIMARY KEY, parent_id INT NOT NULL, CONSTRAINT %1$s_fk FOREIGN KEY (parent_id) REFERENCES %s (id))', $t('fk_noindex'), $t('parent')));
        $s->exec(\sprintf('CREATE TABLE %s (post_id INT NOT NULL, tag_id INT NOT NULL)', $t('junction')));
        $s->exec(\sprintf('CREATE TABLE %s (id CHAR(36) NOT NULL PRIMARY KEY, v INT NOT NULL)', $t('uuid_key')));

        foreach (['twin_a', 'twin_b'] as $twin) {
            $s->exec(\sprintf('CREATE TABLE %s (id INT NOT NULL PRIMARY KEY, label VARCHAR(30) NOT NULL, created_on DATE NOT NULL)', $t($twin)));
        }

        $s->exec(\sprintf('CREATE TABLE %s (id INT NOT NULL PRIMARY KEY, a INT NOT NULL, b INT NOT NULL)', $t('dup_idx')));
        $s->exec(\sprintf('CREATE INDEX %1$s_a1 ON %1$s (a)', $t('dup_idx')));
        $s->exec(\sprintf('CREATE INDEX %1$s_a2 ON %1$s (a)', $t('dup_idx')));
        $s->exec(\sprintf('CREATE TABLE %s (id INT NOT NULL PRIMARY KEY, a INT NOT NULL, b INT NOT NULL)', $t('prefix_idx')));
        $s->exec(\sprintf('CREATE INDEX %1$s_a ON %1$s (a)', $t('prefix_idx')));
        $s->exec(\sprintf('CREATE INDEX %1$s_ab ON %1$s (a, b)', $t('prefix_idx')));

        // Copie sans index ni clé : CREATE TABLE … AS SELECT ne recopie que les colonnes.
        $s->exec(\sprintf('CREATE TABLE %s AS SELECT id, name FROM %s', $t('copy'), $t('parent')));

        $s->exec(\sprintf('CREATE TABLE %s (id INT NOT NULL PRIMARY KEY, price %s NOT NULL)', $t('price_float'), $postgres ? 'REAL' : 'FLOAT'));

        // Collations différentes sur deux colonnes de même rôle.
        $s->exec(\sprintf('CREATE TABLE %s (code VARCHAR(20) %s NOT NULL PRIMARY KEY)', $t('collate_a'), $postgres ? 'COLLATE "C"' : 'COLLATE utf8mb4_bin'));
        $s->exec(\sprintf('CREATE TABLE %s (code VARCHAR(20) %s NOT NULL PRIMARY KEY)', $t('collate_b'), $postgres ? 'COLLATE "und-x-icu"' : 'COLLATE utf8mb4_general_ci'));

        if ($postgres) {
            $s->exec(\sprintf('CREATE TABLE %s (id SMALLINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, v INT NOT NULL)', $t('counter')));
            $s->exec(\sprintf("SELECT setval(pg_get_serial_sequence('%s', 'id'), 30000)", $t('counter')));
            // Une clé étrangère d'un entier vers un numeric : l'index de la colonne ne sert plus aux jointures.
            $s->exec(\sprintf('CREATE TABLE %s (id NUMERIC(10,0) NOT NULL PRIMARY KEY)', $t('num_parent')));
            $s->exec(\sprintf('CREATE TABLE %s (id INT NOT NULL PRIMARY KEY, ref INT NOT NULL, CONSTRAINT %1$s_fk FOREIGN KEY (ref) REFERENCES %s (id))', $t('mismatch'), $t('num_parent')));
            $s->exec(\sprintf('CREATE INDEX %1$s_ref ON %1$s (ref)', $t('mismatch')));
        } else {
            $s->exec(\sprintf('CREATE TABLE %s (id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, v INT NOT NULL)', $t('counter')));
            $s->exec(\sprintf('ALTER TABLE %s AUTO_INCREMENT = 240', $t('counter')));
            $s->exec(\sprintf('INSERT INTO %s (v) VALUES (1)', $t('counter')));
            $s->exec(\sprintf('CREATE TABLE %s (id INT NOT NULL PRIMARY KEY, v INT NOT NULL) ENGINE = MyISAM', $t('myisam')));
        }
    }
}

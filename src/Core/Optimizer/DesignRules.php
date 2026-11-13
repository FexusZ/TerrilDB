<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

use App\Core\Connection\Engine;
use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;

/**
 * Règles de CONCEPTION de l'audit de schéma (suite de #78) : ce qu'une table dit d'elle-même, jugé sans exécuter quoi que ce
 * soit. Chaque règle préfère se taire que se tromper : une information absente (jeu de caractères inconnu, table voisine non
 * lue) ou ambiguë ne donne jamais de constat. Les suggestions sont du texte à relire ; un nom d'objet qui ne peut pas être
 * cité sans risque (caractère de contrôle) n'en reçoit pas.
 */
final class DesignRules
{
    /**
     * @param list<TableDefinition> $tables toutes les tables lues (les voisines servent aux règles de relation)
     *
     * @return list<Finding>
     */
    public function check(Engine $engine, TableDefinition $table, array $tables): array
    {
        $findings = [];
        if (Engine::MySql === $engine || Engine::MariaDb === $engine) {
            array_push($findings, ...$this->legacyCharacterSet($engine, $table));
        }
        if (Engine::MySql === $engine) {
            array_push($findings, ...$this->timestampDeadlines($table));
        }
        array_push($findings, ...$this->implicitForeignKeys($engine, $table, $tables));
        array_push($findings, ...$this->collationMismatches($table, $tables));
        array_push($findings, ...$this->twinTable($table, $tables));
        array_push($findings, ...$this->datesAsText($table));

        return $findings;
    }

    /**
     * utf8 et utf8mb3 : trois octets par caractère, donc ni émojis ni une partie des idéogrammes (erreur 1366), et l'alias
     * « utf8 » est déprécié. Une seule constatation par table, avec toutes les colonnes concernées.
     *
     * @return list<Finding>
     */
    private function legacyCharacterSet(Engine $engine, TableDefinition $table): array
    {
        $columns = [];
        foreach ($table->columns as $column) {
            if (\in_array($column->characterSet, ['utf8', 'utf8mb3'], true)) {
                $columns[] = $column->name;
            }
        }
        if ([] === $columns) {
            return [];
        }

        $name = self::quote($engine, $table->name);

        return [new Finding(
            FindingRule::LegacyCharacterSet,
            Severity::Warning,
            $table->name,
            $columns,
            suggestion: null === $name ? null : \sprintf('ALTER TABLE %s CONVERT TO CHARACTER SET utf8mb4;', $name),
        )];
    }

    /**
     * Une colonne « xxx_id » qui désigne la clé primaire d'une table « xxx » sans qu'aucune clé étrangère le dise : MySQL et
     * MariaDB acceptent `col INT REFERENCES t(id)` sans créer de contrainte, et beaucoup de schémas n'en déclarent jamais. Rien
     * ne protège alors la relation (orphelins) ni ne l'indexe. Il faut tout à la fois : un nom qui concorde avec UNE seule
     * table (xxx, xxxs, xxxes, xxxies pour xxxy), la clé primaire de cette table sur UNE colonne nommée `id` (ou comme la
     * colonne), et exactement le même type. La colonne qui est à elle seule la clé primaire de sa table n'est pas jugée.
     *
     * @param list<TableDefinition> $tables
     *
     * @return list<Finding>
     */
    private function implicitForeignKeys(Engine $engine, TableDefinition $table, array $tables): array
    {
        $findings = [];
        foreach ($this->implicitRelations($table, $tables) as [$column, $target, $referenced]) {
            $own = self::quote($engine, $table->name);
            $col = self::quote($engine, $column->name);
            $ref = self::quote($engine, $target->name);
            $refCol = self::quote($engine, $referenced->name);
            $findings[] = new Finding(
                FindingRule::ImplicitForeignKey,
                Severity::Info,
                $table->name,
                [$column->name],
                suggestion: null === $own || null === $col || null === $ref || null === $refCol
                    ? null
                    : \sprintf('ALTER TABLE %s ADD FOREIGN KEY (%s) REFERENCES %s (%s);', $own, $col, $ref, $refCol),
                detail: $target->name.'('.$referenced->name.')',
            );
        }

        return $findings;
    }

    /**
     * Les relations que le nom et le type laissent deviner sans clé déclarée : colonne, table visée, colonne visée.
     *
     * @param list<TableDefinition> $tables
     *
     * @return list<array{ColumnDefinition, TableDefinition, ColumnDefinition}>
     */
    private function implicitRelations(TableDefinition $table, array $tables): array
    {
        $declared = [];
        foreach ($table->foreignKeys as $key) {
            array_push($declared, ...$key->columns);
        }
        $primary = $table->primaryKey();

        $relations = [];
        foreach ($table->columns as $column) {
            if (!str_ends_with($column->name, '_id') || \strlen($column->name) <= 3 || \in_array($column->name, $declared, true)) {
                continue;
            }
            if (null !== $primary && [$column->name] === $primary->columns) {
                continue;
            }

            $target = $this->namedTable(substr($column->name, 0, -3), $table, $tables);
            $key = $target?->primaryKey();
            if (null === $target || null === $key || 1 !== \count($key->columns) || !\in_array($key->columns[0], ['id', $column->name], true)) {
                continue;
            }
            $referenced = $target->column($key->columns[0]);
            if (null === $referenced || self::normalizedType($referenced->type) !== self::normalizedType($column->type)) {
                continue;
            }
            $relations[] = [$column, $target, $referenced];
        }

        return $relations;
    }

    /**
     * Deux colonnes de texte jointes par une relation IMPLICITE (une clé étrangère déclarée n'est pas jugée : les quatre moteurs de test refusent de la créer entre deux collations, mesuré) de collations différentes : la comparaison
     * échoue (erreur 1267 « Illegal mix of collations ») ou, si le jeu de caractères diffère aussi, convertit l'une des deux
     * et n'utilise plus son index. Aucune suggestion : changer une collation redit toute la définition de la colonne, à faire à
     * la main. Seules des collations CONNUES des deux côtés sont comparées (MySQL, MariaDB).
     *
     * @param list<TableDefinition> $tables
     *
     * @return list<Finding>
     */
    private function collationMismatches(TableDefinition $table, array $tables): array
    {
        $findings = [];
        foreach ($this->implicitRelations($table, $tables) as [$own, $target, $referenced]) {
            if (null === $own->collation || null === $referenced->collation || $own->collation === $referenced->collation) {
                continue;
            }
            $findings[] = new Finding(
                FindingRule::CollationMismatch,
                Severity::Warning,
                $table->name,
                [$own->name],
                detail: \sprintf('%s ≠ %s (%s.%s)', $own->collation, $referenced->collation, $target->name, $referenced->name),
            );
        }

        return $findings;
    }

    /**
     * Une table « xxx_old », « xxx_bak », « xxx_backup » ou « xxx_copy » dont la voisine « xxx » existe avec EXACTEMENT les
     * mêmes colonnes (noms, types, ordre) : un reste de sauvegarde ou de migration, qui occupe de la place et qu'on finit par
     * prendre pour la vraie. Seules ces quatre terminaisons comptent, et jamais sans la voisine. Si la copie n'a aucun index
     * alors que sa voisine en a (le signe d'un `CREATE TABLE … AS SELECT`, qui ne recopie ni clé ni index), un second constat
     * le dit. Rien n'est suggéré : supprimer une table ne se propose pas à la légère.
     *
     * @param list<TableDefinition> $tables
     *
     * @return list<Finding>
     */
    private function twinTable(TableDefinition $table, array $tables): array
    {
        if (1 !== preg_match('/^(.+)_(?:old|bak|backup|copy)$/i', $table->name, $match)) {
            return [];
        }

        $neighbour = null;
        foreach ($tables as $candidate) {
            if (TableKind::Table === $candidate->kind && $candidate->name === $match[1]) {
                $neighbour = $candidate;
                break;
            }
        }
        if (null === $neighbour || self::shape($neighbour) !== self::shape($table)) {
            return [];
        }

        $findings = [new Finding(FindingRule::TwinTable, Severity::Info, $table->name, detail: $neighbour->name)];
        if ([] === $table->indexes && [] !== $neighbour->indexes) {
            $findings[] = new Finding(FindingRule::CopyWithoutIndexes, Severity::Info, $table->name, detail: $neighbour->name);
        }

        return $findings;
    }

    /**
     * Les colonnes d'une table, noms et types dans l'ordre : ce qui fait qu'une table en copie une autre.
     *
     * @return list<array{string, string}>
     */
    private static function shape(TableDefinition $table): array
    {
        return array_map(static fn (ColumnDefinition $c): array => [$c->name, self::normalizedType($c->type)], $table->columns);
    }

    /**
     * Noms de colonnes qui disent « une échéance », donc une date qui peut tomber loin dans le futur.
     */
    private const DEADLINE_NAME = '/(^|_)(expir\w*|deadline|renew\w*|valid_(until|to|through)|due_(at|date|on)|ends?_(at|date|on))($|_)/';

    /**
     * Une colonne TIMESTAMP au nom d'échéance (expires_at, valid_until, end_date…) : sur MySQL le type s'arrête au 19 janvier
     * 2038 et refuse au-delà (mesuré sur 8.4 et 26) ; DATETIME va jusqu'à l'an 9999. MySQL seulement : MariaDB 11.5 et plus étend
     * TIMESTAMP à 2106 (mesuré), PostgreSQL n'a pas cette limite. Heuristique de nom, donc « à vérifier » ; pas de SQL, un
     * changement de type redit toute la définition de la colonne.
     *
     * @return list<Finding>
     */
    private function timestampDeadlines(TableDefinition $table): array
    {
        $findings = [];
        foreach ($table->columns as $column) {
            if ('timestamp' === self::baseType($column->type) && 1 === preg_match(self::DEADLINE_NAME, strtolower($column->name))) {
                $findings[] = new Finding(FindingRule::TimestampDeadline, Severity::Info, $table->name, [$column->name], detail: $column->type);
            }
        }

        return $findings;
    }

    private const DATE_NAME = '/^(date|datetime|birthday|birthdate|dob|created_at|updated_at|deleted_at)$|_date$|^date_|_datetime$/';

    /**
     * Mots qui font d'une colonne « date_… » autre chose qu'une date (un format, un libellé, une remarque).
     */
    private const NOT_A_DATE = '/format|pattern|fmt|label|text|name|desc|comment|note|mask|type|range|string|regex/';

    private const TEXT_TYPES = ['char', 'varchar', 'character', 'character varying', 'bpchar', 'text', 'tinytext', 'mediumtext', 'longtext'];

    /**
     * Une colonne de TEXTE dont le nom dit une date (date, birth_date, created_at…) : le moteur ne contrôle plus la forme (un
     * « 31/02 » passe), trie en ordre alphabétique et ne sait plus calculer. Heuristique de nom, donc « à vérifier » ; les
     * noms qui parlent d'un format, d'un libellé ou d'une remarque sont écartés. Pas de SQL : la conversion dépend du format
     * réellement stocké.
     *
     * @return list<Finding>
     */
    private function datesAsText(TableDefinition $table): array
    {
        $findings = [];
        foreach ($table->columns as $column) {
            $name = strtolower($column->name);
            if (\in_array(self::baseType($column->type), self::TEXT_TYPES, true) && 1 === preg_match(self::DATE_NAME, $name) && 1 !== preg_match(self::NOT_A_DATE, $name)) {
                $findings[] = new Finding(FindingRule::DateAsText, Severity::Info, $table->name, [$column->name], detail: $column->type);
            }
        }

        return $findings;
    }

    /**
     * Le type sans ses paramètres (longueur, précision), en minuscules : « timestamp(3) » -> « timestamp ».
     */
    private static function baseType(string $type): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/\([^)]*\)/', '', strtolower($type))));
    }

    /**
     * La seule table, autre que `$from`, qui porte ce nom au singulier ou à son pluriel simple ; null si aucune ou si
     * plusieurs (dans le doute, on se tait).
     *
     * @param list<TableDefinition> $tables
     */
    private function namedTable(string $stem, TableDefinition $from, array $tables): ?TableDefinition
    {
        $names = [$stem, $stem.'s', $stem.'es'];
        if (str_ends_with($stem, 'y')) {
            $names[] = substr($stem, 0, -1).'ies';
        }

        $found = [];
        foreach ($tables as $candidate) {
            if (TableKind::Table === $candidate->kind && $candidate->name !== $from->name && \in_array(strtolower($candidate->name), $names, true)) {
                $found[] = $candidate;
            }
        }

        return 1 === \count($found) ? $found[0] : null;
    }

    /**
     * Le type sans la casse ni les espaces en trop.
     */
    private static function normalizedType(string $type): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strtolower($type)));
    }

    /**
     * Un identifiant cité, ou null s'il porte un caractère de contrôle (retour à la ligne, NUL…) qui ferait dire autre chose
     * au texte montré.
     */
    private static function quote(Engine $engine, string $identifier): ?string
    {
        if (1 === preg_match('/[\x00-\x1F\x7F]/', $identifier)) {
            return null;
        }

        return Engine::PostgreSql === $engine ? '"'.str_replace('"', '""', $identifier).'"' : '`'.str_replace('`', '``', $identifier).'`';
    }
}

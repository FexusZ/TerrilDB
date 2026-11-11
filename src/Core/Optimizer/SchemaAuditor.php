<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

use App\Core\Connection\Engine;
use App\Core\Schema\ForeignKeyDefinition;
use App\Core\Schema\IndexDefinition;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;

/**
 * Audit d'un schéma par règles simples et sûres, à partir de ce que l'introspection sait déjà : tables sans clé
 * primaire, clés étrangères sans index (jointures et suppressions en cascade qui parcourent toute la table) et index
 * redondants (écriture plus lente pour rien). Les vues ne sont pas auditées.
 *
 * Un index unique n'est jamais jugé redondant : il garantit quelque chose. Les suggestions sont du texte.
 */
final class SchemaAuditor
{
    private const NAME_LIMIT = 63;

    /**
     * @param list<TableDefinition> $tables
     *
     * @return list<Finding>
     */
    public function audit(Engine $engine, array $tables): array
    {
        if (Engine::ClickHouse === $engine) {
            // Ni clé primaire, ni clé étrangère, ni unicité : les règles relationnelles ne s'appliquent pas.
            return [];
        }

        $findings = [];
        foreach ($tables as $table) {
            if (TableKind::Table !== $table->kind) {
                continue;
            }
            array_push($findings, ...$this->auditTable($engine, $table, $tables));
        }

        // Par table, puis les constats graves d'abord.
        $rank = static fn (Finding $f): array => [$f->table, Severity::Warning === $f->severity ? 0 : 1, $f->rule->value, $f->index ?? implode(',', $f->columns)];
        usort($findings, static fn (Finding $a, Finding $b): int => $rank($a) <=> $rank($b));

        return $findings;
    }

    /**
     * @param list<TableDefinition> $tables
     *
     * @return list<Finding>
     */
    private function auditTable(Engine $engine, TableDefinition $table, array $tables): array
    {
        $findings = [];
        if (null === $table->primaryKey()) {
            $findings[] = new Finding(FindingRule::MissingPrimaryKey, Severity::Warning, $table->name);
        }

        $textKey = $this->textPrimaryKey($engine, $table);
        if (null !== $textKey) {
            $findings[] = $textKey;
        }

        array_push($findings, ...$this->floatAmounts($engine, $table));

        foreach ($table->foreignKeys as $key) {
            if (!$this->isCovered($key, $table->indexes)) {
                $findings[] = new Finding(
                    FindingRule::ForeignKeyWithoutIndex,
                    Severity::Warning,
                    $table->name,
                    $key->columns,
                    suggestion: $this->createIndex($engine, $table->name, $key->columns),
                );
            }
        }

        foreach ($table->foreignKeys as $key) {
            array_push($findings, ...$this->typeMismatches($engine, $table, $key, $tables));
        }

        foreach ($table->indexes as $index) {
            $covering = $this->coveredBy($index, $table->indexes);
            if (null !== $covering) {
                $findings[] = new Finding(
                    FindingRule::RedundantIndex,
                    Severity::Info,
                    $table->name,
                    $index->columns,
                    $index->name,
                    $covering->name,
                    $this->dropIndex($engine, $table->name, $index->name),
                );
            }
        }

        return $findings;
    }

    /**
     * Un UUID rangé en texte (char(36) ou varchar(36)) comme clé primaire d'une seule colonne : 36 octets au lieu de 16, et
     * InnoDB recopie la clé primaire dans chaque index secondaire. Seule la longueur 36 est jugée (un varchar quelconque peut
     * être un identifiant voulu). Le SQL n'est proposé que pour PostgreSQL, où `USING` convertit les valeurs : un MODIFY en
     * BINARY(16) sur MySQL/MariaDB tronquerait le texte, il faut passer par une colonne intermédiaire (UUID_TO_BIN).
     */
    private function textPrimaryKey(Engine $engine, TableDefinition $table): ?Finding
    {
        $primary = $table->primaryKey();
        if (null === $primary || 1 !== \count($primary->columns)) {
            return null;
        }
        $column = $table->column($primary->columns[0]);
        if (null === $column || 1 !== preg_match('/^(?:char|character|varchar|character varying)\(36\)$/', trim(strtolower($column->type)))) {
            return null;
        }

        return new Finding(
            FindingRule::TextPrimaryKey,
            Severity::Warning,
            $table->name,
            [$column->name],
            suggestion: Engine::PostgreSql === $engine
                ? \sprintf('ALTER TABLE %1$s ALTER COLUMN %2$s TYPE uuid USING %2$s::uuid;', self::quote($engine, $table->name), self::quote($engine, $column->name))
                : null,
            detail: $column->type,
        );
    }

    /** Mots (le nom coupé sur `_`, les tirets, les espaces et les majuscules) qui désignent une somme d'argent. */
    private const MONEY_WORDS = ['price', 'prix', 'amount', 'montant', 'total', 'cost', 'cout', 'tarif', 'salary', 'salaire', 'balance', 'solde', 'fee', 'frais', 'revenue'];

    /** Mots qui disent qu'il ne s'agit pas d'une somme (unité entière, mesure, rapport) : ils écartent la colonne. */
    private const NOT_MONEY_WORDS = ['cents', 'cent', 'centimes', 'weight', 'poids', 'rate', 'taux', 'ratio', 'score', 'percent', 'pct', 'count', 'qty', 'quantity', 'nb', 'size', 'length', 'duration', 'distance'];

    /**
     * Une colonne au nom évocateur d'une somme d'argent (prix, montant, total…) en virgule flottante : les flottants
     * binaires n'écrivent pas exactement 0,10 et les écarts d'arrondi s'accumulent dans les sommes. Heuristique sur le NOM :
     * sévérité « info », à vérifier. Le SQL ne vient que quand il ne peut rien perdre : PostgreSQL garde défaut et nullabilité,
     * MySQL/MariaDB MODIFY redit la définition, donc rien si la colonne a un défaut, un commentaire ou un auto-incrément.
     *
     * @return list<Finding>
     */
    private function floatAmounts(Engine $engine, TableDefinition $table): array
    {
        $findings = [];
        foreach ($table->columns as $column) {
            $type = strtolower(trim($column->type));
            if (1 !== preg_match('/^(?:float|double|real)\b/', $type)) {
                continue;
            }
            $words = preg_split('/[^a-z0-9]+/', strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $column->name)), -1, \PREG_SPLIT_NO_EMPTY);
            if (false === $words || [] === array_intersect($words, self::MONEY_WORDS) || [] !== array_intersect($words, self::NOT_MONEY_WORDS)) {
                continue;
            }

            $suggestion = null;
            if (Engine::PostgreSql === $engine) {
                $suggestion = \sprintf('ALTER TABLE %s ALTER COLUMN %s TYPE numeric(12,2);', self::quote($engine, $table->name), self::quote($engine, $column->name));
            } elseif (null === $column->default && null === $column->comment && !$column->autoIncrement) {
                $suggestion = \sprintf('ALTER TABLE %s MODIFY %s DECIMAL(12,2) %s;', self::quote($engine, $table->name), self::quote($engine, $column->name), $column->nullable ? 'NULL' : 'NOT NULL');
            }

            $findings[] = new Finding(FindingRule::FloatAmount, Severity::Info, $table->name, [$column->name], suggestion: $suggestion, detail: $column->type);
        }

        return $findings;
    }

    /**
     * Familles d'opérateurs de PostgreSQL pour les types qu'on sait comparer : deux types de la même famille se comparent
     * sans convertir la colonne indexée (smallint, integer et bigint ; varchar et text).
     */
    private const FAMILIES = [
        'smallint' => 'integer', 'integer' => 'integer', 'bigint' => 'integer',
        'numeric' => 'numeric', 'decimal' => 'numeric',
        'real' => 'float', 'double precision' => 'float',
        'text' => 'text', 'character varying' => 'text', 'varchar' => 'text',
        'character' => 'bpchar', 'char' => 'bpchar', 'bpchar' => 'bpchar',
    ];

    /**
     * Pour une colonne de la famille de la clé, les familles référencées qui obligent le moteur à CONVERTIR la colonne qui
     * porte la clé (donc à ne plus se servir de son index). Mesuré sur PostgreSQL 18 : un entier vers un numeric ou un
     * flottant, un char(n) vers un text. L'inverse, ou deux types d'une même famille, garde l'index.
     */
    private const CONVERTED = [
        'integer' => ['numeric', 'float'],
        'bpchar' => ['text'],
    ];

    /**
     * Une clé étrangère qui oblige le moteur à convertir sa propre colonne pour la comparer à la colonne référencée : son
     * index ne sert alors plus dans les jointures. Seul PostgreSQL est jugé : ailleurs le moteur refuse déjà de créer une
     * clé entre des types incompatibles. La table ou la colonne référencée absente de la liste (autre schéma, table non
     * lue) n'est pas jugée, ni un type hors des familles connues (dans le doute, on se tait).
     *
     * @param list<TableDefinition> $tables
     *
     * @return list<Finding>
     */
    private function typeMismatches(Engine $engine, TableDefinition $table, ForeignKeyDefinition $key, array $tables): array
    {
        if (Engine::PostgreSql !== $engine) {
            return [];
        }

        $target = null;
        foreach ($tables as $candidate) {
            if ($candidate->name === $key->referencedTable) {
                $target = $candidate;
                break;
            }
        }
        if (null === $target) {
            return [];
        }

        $findings = [];
        foreach ($key->columns as $position => $name) {
            $own = $table->column($name);
            $referenced = $target->column($key->referencedColumns[$position]);
            if (null === $own || null === $referenced) {
                continue;
            }
            $ownFamily = self::FAMILIES[self::baseType($own->type)] ?? null;
            $referencedFamily = self::FAMILIES[self::baseType($referenced->type)] ?? null;
            if (null === $ownFamily || null === $referencedFamily || !\in_array($referencedFamily, self::CONVERTED[$ownFamily] ?? [], true)) {
                continue;
            }
            $findings[] = new Finding(
                FindingRule::ForeignKeyTypeMismatch,
                Severity::Warning,
                $table->name,
                [$name],
                suggestion: \sprintf('ALTER TABLE %s ALTER COLUMN %s TYPE %s;', self::quote($engine, $table->name), self::quote($engine, $name), $referenced->type),
                detail: $own->type.' → '.$referenced->type,
            );
        }

        return $findings;
    }

    /**
     * Le type sans ses paramètres (longueur, précision), en minuscules : « CHARACTER VARYING(50) » -> « character varying ».
     */
    private static function baseType(string $type): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/\([^)]*\)/', '', strtolower($type))));
    }

    /**
     * @param list<IndexDefinition> $indexes
     */
    private function isCovered(ForeignKeyDefinition $key, array $indexes): bool
    {
        $wanted = $key->columns;
        sort($wanted);

        foreach ($indexes as $index) {
            $leading = \array_slice($index->columns, 0, \count($wanted));
            sort($leading);
            if ($leading === $wanted) {
                return true;
            }
        }

        return false;
    }

    /**
     * L'index qui rend `$index` superflu, s'il y en a un.
     *
     * @param list<IndexDefinition> $indexes
     */
    private function coveredBy(IndexDefinition $index, array $indexes): ?IndexDefinition
    {
        if ($index->unique || $index->primary) {
            return null;
        }

        $candidates = [];
        foreach ($indexes as $other) {
            if ($other->name === $index->name || \array_slice($other->columns, 0, \count($index->columns)) !== $index->columns) {
                continue;
            }
            $sameColumns = \count($other->columns) === \count($index->columns);
            // À colonnes identiques, seul un index plus fort (unique) ou plus ancien (par nom) en dispense.
            if ($sameColumns && !$other->unique && !$other->primary && strcmp($other->name, $index->name) > 0) {
                continue;
            }
            $candidates[] = $other;
        }

        usort($candidates, static fn (IndexDefinition $a, IndexDefinition $b): int => [!($a->unique || $a->primary), $a->name] <=> [!($b->unique || $b->primary), $b->name]);

        return $candidates[0] ?? null;
    }

    /**
     * @param list<string> $columns
     */
    private function createIndex(Engine $engine, string $table, array $columns): string
    {
        return \sprintf(
            'CREATE INDEX %s ON %s (%s);',
            self::indexName($table, $columns),
            self::quote($engine, $table),
            implode(', ', array_map(static fn (string $column): string => self::quote($engine, $column), $columns)),
        );
    }

    private function dropIndex(Engine $engine, string $table, string $index): string
    {
        return Engine::PostgreSql === $engine
            ? \sprintf('DROP INDEX %s;', self::quote($engine, $index))
            : \sprintf('ALTER TABLE %s DROP INDEX %s;', self::quote($engine, $table), self::quote($engine, $index));
    }

    /**
     * @param list<string> $columns
     */
    private static function indexName(string $table, array $columns): string
    {
        $name = strtolower('idx_'.$table.'_'.implode('_', $columns));
        $name = trim((string) preg_replace('/[^a-z0-9_]+/', '_', $name), '_');
        if (\strlen($name) > self::NAME_LIMIT) {
            $name = substr($name, 0, self::NAME_LIMIT - 9).'_'.substr(sha1($name), 0, 8);
        }

        return $name;
    }

    private static function quote(Engine $engine, string $identifier): string
    {
        return Engine::PostgreSql === $engine ? '"'.str_replace('"', '""', $identifier).'"' : '`'.str_replace('`', '``', $identifier).'`';
    }
}

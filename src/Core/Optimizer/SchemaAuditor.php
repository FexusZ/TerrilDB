<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

use App\Core\Connection\Engine;
use App\Core\Schema\AutoCounter;
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
     * @param list<AutoCounter>     $counters les compteurs automatiques lus au catalogue (sans eux, la règle de saturation se tait)
     *
     * @return list<Finding>
     */
    public function audit(Engine $engine, array $tables, array $counters = []): array
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
            array_push($findings, ...$this->auditTable($engine, $table, $tables), ...$this->saturation($engine, $table, $counters));
        }

        // Par table, puis les constats graves d'abord.
        $rank = static fn (Finding $f): array => [$f->table, match ($f->severity) {
            Severity::Critical => 0, Severity::Warning => 1, Severity::Info => 2,
        }, $f->rule->value, $f->index ?? implode(',', $f->columns)];
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

    private const WARNING_RATIO = 0.7;
    private const CRITICAL_RATIO = 0.9;

    /** Largeur des entiers : [signé, non signé]. Les bornes sont des flottants : seul le rapport compte ici. */
    private const INTEGER_LIMITS = [
        'tinyint' => [127.0, 255.0],
        'smallint' => [32767.0, 65535.0],
        'mediumint' => [8388607.0, 16777215.0],
        'int' => [2147483647.0, 4294967295.0],
        'integer' => [2147483647.0, 4294967295.0],
        'bigint' => [9223372036854775807.0, 18446744073709551615.0],
    ];

    private const BIGINT_MAX = '9223372036854775807';

    /**
     * Compteur proche de la limite de son type (ou de sa séquence) : à 70 % un avertissement, à 90 % un constat grave. Le
     * catalogue donne la dernière valeur attribuée ; un type qui n'est pas un entier connu n'est pas jugé. La suggestion est du
     * texte à relire : changer le type réécrit la table, et les colonnes qui la référencent doivent suivre.
     *
     * @param list<AutoCounter> $counters
     *
     * @return list<Finding>
     */
    private function saturation(Engine $engine, TableDefinition $table, array $counters): array
    {
        $findings = [];
        foreach ($counters as $counter) {
            $column = $counter->table === $table->name ? $table->column($counter->column) : null;
            if (null === $column || !is_numeric($counter->lastValue)) {
                continue;
            }
            $type = self::baseType($column->type);
            $unsigned = str_contains($type, 'unsigned');
            $width = trim(str_replace(['unsigned', 'zerofill'], '', $type));
            $limits = self::INTEGER_LIMITS[$width] ?? null;
            if (null === $limits) {
                continue;
            }
            $typeMax = $limits[$unsigned ? 1 : 0];
            $max = null !== $counter->maxValue && is_numeric($counter->maxValue) ? min($typeMax, (float) $counter->maxValue) : $typeMax;
            $ratio = (float) $counter->lastValue / $max;
            if ($ratio < self::WARNING_RATIO) {
                continue;
            }

            $limitText = null !== $counter->maxValue && is_numeric($counter->maxValue) && (float) $counter->maxValue < $typeMax
                ? $counter->maxValue
                : self::limitText($width, $unsigned);
            $findings[] = new Finding(
                FindingRule::CounterNearLimit,
                $ratio >= self::CRITICAL_RATIO ? Severity::Critical : Severity::Warning,
                $table->name,
                [$column->name],
                suggestion: $this->widen($engine, $table->name, $column->name, $width, $unsigned, $counter, $max < $typeMax),
                detail: \sprintf('%s : %s sur %s (%d %%)', $column->type, $counter->lastValue, $limitText, (int) floor($ratio * 100)),
            );
        }

        return $findings;
    }

    private static function limitText(string $width, bool $unsigned): string
    {
        return match ($width.($unsigned ? ' unsigned' : '')) {
            'tinyint' => '127', 'tinyint unsigned' => '255',
            'smallint' => '32767', 'smallint unsigned' => '65535',
            'mediumint' => '8388607', 'mediumint unsigned' => '16777215',
            'int', 'integer' => '2147483647', 'int unsigned', 'integer unsigned' => '4294967295',
            'bigint' => '9223372036854775807',
            default => '18446744073709551615',
        };
    }

    private function widen(Engine $engine, string $table, string $column, string $width, bool $unsigned, AutoCounter $counter, bool $cappedBySequence): ?string
    {
        if (Engine::PostgreSql === $engine) {
            $sequence = null === $counter->sequence ? null : implode('.', array_map(static fn (string $part): string => self::quote($engine, $part), explode('.', $counter->sequence, 2)));
            if ($cappedBySequence && null !== $sequence) {
                // La séquence est plafonnée plus bas que son type : on lève le plafond (un bigint porte jusque-là).
                return \sprintf('ALTER SEQUENCE %s MAXVALUE %s;', $sequence, self::BIGINT_MAX);
            }
            if ('bigint' === $width) {
                return null;
            }
            $alter = \sprintf('ALTER TABLE %s ALTER COLUMN %s TYPE bigint;', self::quote($engine, $table), self::quote($engine, $column));

            // Une identité suit le type de sa colonne ; la séquence d'un serial reste un entier tant qu'on ne la change pas.
            return null === $sequence || $counter->identity ? $alter : $alter.'
'.\sprintf('ALTER SEQUENCE %s AS bigint;', $sequence);
        }

        if ('bigint' === $width && $unsigned) {
            return null;
        }

        return \sprintf(
            'ALTER TABLE %s MODIFY %s BIGINT%s NOT NULL AUTO_INCREMENT;',
            self::quote($engine, $table),
            self::quote($engine, $column),
            $unsigned || 'bigint' === $width ? ' UNSIGNED' : '',
        );
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

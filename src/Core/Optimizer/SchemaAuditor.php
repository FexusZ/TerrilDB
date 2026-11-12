<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

use App\Core\Connection\Engine;
use App\Core\Schema\DefinerKind;
use App\Core\Schema\DefinerObject;
use App\Core\Schema\DefinerReport;
use App\Core\Schema\ForeignKeyDefinition;
use App\Core\Schema\IndexDefinition;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;
use App\Core\Schema\TableUpkeep;
use App\Core\Schema\UpkeepReport;

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

    // ---- Audit d'exploitation (#80, suite). Bloc séparé : seuils fixes, chacun au moins deux fois ce que le moteur tolère seul. ----

    private const STALE_MIN_ROWS = 10000;
    private const STALE_PERCENT = 20;
    private const DEAD_MIN_ROWS = 10000;
    private const DEAD_PERCENT = 40;
    private const FREE_MIN_BYTES = 67108864;
    private const FREE_PERCENT = 30;

    /**
     * Les règles d'exploitation, lues dans des rapports déjà faits : statistiques périmées et tuples morts (PostgreSQL), place
     * libre dans le fichier d'une table et créateur disparu (MySQL, MariaDB). Un rapport illisible ne donne rien ici (voir
     * operationsChecks). Les suggestions sont du texte à relire ; les remèdes lourds disent leur coût.
     *
     * @return list<Finding>
     */
    public function operations(Engine $engine, ?UpkeepReport $upkeep, ?DefinerReport $definers): array
    {
        $findings = [];
        if (null !== $upkeep && $upkeep->available) {
            $tables = $upkeep->tables;
            usort($tables, static fn (TableUpkeep $a, TableUpkeep $b): int => strcmp($a->table, $b->table));
            foreach ($tables as $table) {
                array_push($findings, ...$this->upkeepFindings($engine, $table));
            }
        }
        if (null !== $definers && $definers->available && $this->isMySqlFamily($engine)) {
            $objects = $definers->objects;
            usort($objects, static fn (DefinerObject $a, DefinerObject $b): int => strcmp($a->name, $b->name));
            foreach ($objects as $object) {
                $findings[] = $this->orphan($engine, $object);
            }
        }

        return $findings;
    }

    /**
     * Les règles d'exploitation qui n'ont pas pu juger parce que leur source est illisible (droit manquant, compteur absent) :
     * à montrer comme « non vérifié », jamais à confondre avec « rien à signaler ». `not_supported` n'en est pas un.
     *
     * @return list<OperationsCheck>
     */
    public function operationsChecks(Engine $engine, ?UpkeepReport $upkeep, ?DefinerReport $definers): array
    {
        $checks = [];
        if (null !== $upkeep && !$upkeep->available && 'not_supported' !== $upkeep->reason) {
            $rules = match ($engine) {
                Engine::PostgreSql => [FindingRule::StaleStatistics, FindingRule::DeadTuples],
                Engine::MySql, Engine::MariaDb => [FindingRule::ReclaimableSpace],
                default => [],
            };
            foreach ($rules as $rule) {
                $checks[] = new OperationsCheck($rule, $upkeep->reason ?? 'unreadable');
            }
        }
        if (null !== $definers && !$definers->available && 'not_supported' !== $definers->reason && $this->isMySqlFamily($engine)) {
            $checks[] = new OperationsCheck(FindingRule::OrphanDefiner, $definers->reason ?? 'unreadable');
        }

        return $checks;
    }

    private function isMySqlFamily(Engine $engine): bool
    {
        return Engine::MySql === $engine || Engine::MariaDb === $engine;
    }

    /**
     * @return list<Finding>
     */
    private function upkeepFindings(Engine $engine, TableUpkeep $table): array
    {
        $findings = [];
        if (Engine::PostgreSql === $engine) {
            $live = $table->liveRows;
            $modified = $table->modifiedSinceAnalyze;
            // Au moins deux fois le seuil de l'autoanalyze par défaut (10 % + 50) : l'autovacuum en retard, pas en cours de route.
            if (null !== $live && null !== $modified && $modified >= self::STALE_MIN_ROWS && $modified * 100 >= self::STALE_PERCENT * $live) {
                $findings[] = new Finding(
                    FindingRule::StaleStatistics,
                    Severity::Info,
                    $table->table,
                    suggestion: \sprintf('ANALYZE %s;', self::quote($engine, $table->table)),
                    detail: \sprintf('%d lignes modifiées depuis la dernière analyse (%s)', $modified, $table->lastAnalyze ?? 'jamais analysée depuis le début des compteurs'),
                );
            }
            $dead = $table->deadRows;
            // Au moins deux fois le seuil du vacuum par défaut (20 % + 50).
            if (null !== $live && null !== $dead && $dead >= self::DEAD_MIN_ROWS && $dead * 100 >= self::DEAD_PERCENT * $live) {
                $quoted = self::quote($engine, $table->table);
                $findings[] = new Finding(
                    FindingRule::DeadTuples,
                    Severity::Info,
                    $table->table,
                    suggestion: \sprintf("VACUUM (ANALYZE) %s;\n-- VACUUM FULL rend l'espace au système mais pose un verrou exclusif pendant toute la réécriture : à réserver à une fenêtre de maintenance.", $quoted),
                    detail: \sprintf('%d tuples morts pour %d vivants (%s)', $dead, $live, null === $table->lastVacuum ? 'jamais vacuumée depuis le début des compteurs' : 'dernier vacuum : '.$table->lastVacuum),
                );
            }

            return $findings;
        }

        $free = $table->freeBytes;
        if ($this->isMySqlFamily($engine) && null !== $free && $free >= self::FREE_MIN_BYTES) {
            $total = ($table->dataBytes ?? 0) + ($table->indexBytes ?? 0) + $free;
            if ($free * 100 >= self::FREE_PERCENT * $total) {
                $findings[] = new Finding(
                    FindingRule::ReclaimableSpace,
                    Severity::Info,
                    $table->table,
                    suggestion: \sprintf("OPTIMIZE TABLE %s;\n-- reconstruit la table : lit et réécrit toutes ses données, demande autant d'espace disque temporaire que la table et beaucoup d'E/S, avec une courte attente de verrou au début et à la fin ; à lancer hors pointe, jamais un remède universel.", self::quote($engine, $table->table)),
                    detail: \sprintf('environ %d Mio libres dans le fichier de la table (%d %% de sa taille)', (int) round($free / 1048576), (int) round($free * 100 / $total)),
                );
            }
        }

        return $findings;
    }

    private function orphan(Engine $engine, DefinerObject $object): Finding
    {
        [$label, $created, $show, $drop] = match ($object->kind) {
            DefinerKind::View => ['vue', 'créée', 'VIEW', 'DROP VIEW'],
            DefinerKind::Procedure => ['procédure', 'créée', 'PROCEDURE', 'DROP PROCEDURE'],
            DefinerKind::Function => ['fonction', 'créée', 'FUNCTION', 'DROP FUNCTION'],
            DefinerKind::Trigger => ['déclencheur', 'créé', 'TRIGGER', 'DROP TRIGGER'],
            DefinerKind::Event => ['événement', 'créé', 'EVENT', 'DROP EVENT'],
        };
        $name = self::quote($engine, $object->name);
        // Rien d'exécutable : la définition se relit d'abord. Seul un événement se corrige en place (ALTER exige une clause : celle de
        // son état, que SHOW CREATE EVENT dit) ; le reste se recrée.
        $suggestion = DefinerKind::Event === $object->kind
            ? \sprintf("-- relire d'abord : SHOW CREATE EVENT %s;\n-- puis, en gardant son état (ENABLE ou DISABLE selon ce qu'elle indique) : ALTER DEFINER = CURRENT_USER EVENT %s ENABLE;", $name, $name)
            : \sprintf("-- relire d'abord : SHOW CREATE %s %s;\n-- puis %s %s; et le recréer avec DEFINER = un compte qui existe.", $show, $name, $drop, $name);

        return new Finding(
            FindingRule::OrphanDefiner,
            Severity::Warning,
            $object->table ?? $object->name,
            index: $object->name,
            suggestion: $suggestion,
            detail: \sprintf('%s %s par « %s », compte inexistant', $label, $created, $object->definer),
        );
    }

    private static function quote(Engine $engine, string $identifier): string
    {
        return Engine::PostgreSql === $engine ? '"'.str_replace('"', '""', $identifier).'"' : '`'.str_replace('`', '``', $identifier).'`';
    }
}

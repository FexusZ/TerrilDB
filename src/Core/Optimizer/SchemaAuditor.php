<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

use App\Core\Connection\Engine;
use App\Core\Schema\AutoCounter;
use App\Core\Schema\ForeignKeyDefinition;
use App\Core\Schema\IndexDefinition;
use App\Core\Schema\IndexUsageReport;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;
use App\Core\Sql\SqlText;
use App\Core\Sql\UnsafeSqlNameException;

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

    private readonly DesignRules $design;

    public function __construct(?DesignRules $design = null)
    {
        $this->design = $design ?? new DesignRules();
    }

    /**
     * @param list<TableDefinition> $tables
     * @param ?IndexUsageReport     $usage    l'usage des index lu au catalogue (sans lui, la règle des index jamais lus se tait)
     * @param list<AutoCounter>     $counters les compteurs automatiques lus au catalogue (sans eux, la règle de saturation se tait)
     *
     * @return list<Finding>
     */
    public function audit(Engine $engine, array $tables, ?IndexUsageReport $usage = null, array $counters = []): array
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
            array_push($findings, ...$this->auditTable($engine, $table, $tables), ...$this->unusedIndexes($engine, $table, $usage), ...$this->saturation($engine, $table, $counters));
        }

        // Par table, puis les constats graves d'abord.
        $rank = static fn (Finding $f): array => [$f->table, match ($f->severity) {
            Severity::Critical => 0, Severity::Warning => 1, Severity::Info => 2,
        }, $f->rule->value, $f->index ?? implode(',', $f->columns)];
        usort($findings, static fn (Finding $a, Finding $b): int => $rank($a) <=> $rank($b));

        return $findings;
    }

    /**
     * Les vérifications d'exploitation qui n'ont pas pu juger (source illisible, collecte trop récente) : à montrer, jamais à
     * confondre avec « rien à signaler ».
     *
     * @return list<AuditCheck>
     */
    public function checks(Engine $engine, ?IndexUsageReport $usage): array
    {
        if (null === $usage || !$engine->speaksSql() || Engine::ClickHouse === $engine || 'not_supported' === $usage->reason) {
            return [];
        }
        if (!$usage->available) {
            return [new AuditCheck(FindingRule::UnusedIndex, CheckState::Unavailable, $usage->reason)];
        }
        if ($usage->observedSeconds < self::OBSERVATION_SECONDS) {
            return [new AuditCheck(FindingRule::UnusedIndex, CheckState::TooShort, detail: (string) $usage->observedSeconds)];
        }

        return [];
    }

    private const OBSERVATION_SECONDS = 604800;

    /**
     * Index jamais lu depuis le début de la collecte, après au moins sept jours : la clé primaire, un index unique et celui qui
     * porte une clé étrangère ne sont jamais proposés (ils servent aux contraintes même sans lecture). Rien n'est supprimé :
     * MySQL et MariaDB proposent de le rendre invisible d'abord pour essayer sans risque.
     *
     * @return list<Finding>
     */
    private function unusedIndexes(Engine $engine, TableDefinition $table, ?IndexUsageReport $usage): array
    {
        if (null === $usage || !$usage->available || $usage->observedSeconds < self::OBSERVATION_SECONDS) {
            return [];
        }

        $findings = [];
        foreach ($usage->usages as $used) {
            if ($used->table !== $table->name || $used->reads > 0) {
                continue;
            }
            foreach ($table->indexes as $index) {
                if ($index->name !== $used->index || $index->primary || $index->unique || $this->carriesForeignKey($index, $table)) {
                    continue;
                }
                $findings[] = new Finding(
                    FindingRule::UnusedIndex,
                    Severity::Info,
                    $table->name,
                    $index->columns,
                    $index->name,
                    suggestion: $this->retire($engine, $table->name, $index->name),
                    detail: 'aucune lecture en '.self::days($usage->observedSeconds),
                );
            }
        }

        return $findings;
    }

    private function carriesForeignKey(IndexDefinition $index, TableDefinition $table): bool
    {
        foreach ($table->foreignKeys as $key) {
            $leading = \array_slice($index->columns, 0, \count($key->columns));
            $wanted = $key->columns;
            sort($leading);
            sort($wanted);
            if ($leading === $wanted) {
                return true;
            }
        }

        return false;
    }

    private static function days(int $seconds): string
    {
        $days = intdiv($seconds, 86400);

        return $days.(1 === $days ? ' jour' : ' jours');
    }

    private function retire(Engine $engine, string $table, string $index): ?string
    {
        return self::safely(fn (): string => $this->retireSql($engine, $table, $index));
    }

    private function retireSql(Engine $engine, string $table, string $index): string
    {
        $note = '-- après une période représentative, si rien n\'a ralenti :';
        $quotedTable = SqlText::quoteIdentifier($engine, $table);
        $quotedIndex = SqlText::quoteIdentifier($engine, $index);
        if (Engine::PostgreSql === $engine) {
            return "-- PostgreSQL n'a pas d'index invisible : à supprimer seulement après une période représentative.\n-- DROP INDEX {$quotedIndex};";
        }

        return \sprintf("ALTER TABLE %s ALTER INDEX %s %s;\n%s\n-- ALTER TABLE %s DROP INDEX %s;", $quotedTable, $quotedIndex, Engine::MariaDb === $engine ? 'IGNORED' : 'INVISIBLE', $note, $quotedTable, $quotedIndex);
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

        array_push($findings, ...$this->design->check($engine, $table, $tables));

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
        try {
            return $this->widenSql($engine, $table, $column, $width, $unsigned, $counter, $cappedBySequence);
        } catch (UnsafeSqlNameException) {
            return null;
        }
    }

    private function widenSql(Engine $engine, string $table, string $column, string $width, bool $unsigned, AutoCounter $counter, bool $cappedBySequence): ?string
    {
        if (Engine::PostgreSql === $engine) {
            $sequence = null === $counter->sequence ? null : implode('.', array_map(static fn (string $part): string => SqlText::quoteIdentifier($engine, $part), explode('.', $counter->sequence, 2)));
            if ($cappedBySequence && null !== $sequence) {
                // La séquence est plafonnée plus bas que son type : on lève le plafond (un bigint porte jusque-là).
                return \sprintf('ALTER SEQUENCE %s MAXVALUE %s;', $sequence, self::BIGINT_MAX);
            }
            if ('bigint' === $width) {
                return null;
            }
            $alter = \sprintf('ALTER TABLE %s ALTER COLUMN %s TYPE bigint;', SqlText::quoteIdentifier($engine, $table), SqlText::quoteIdentifier($engine, $column));

            // Une identité suit le type de sa colonne ; la séquence d'un serial reste un entier tant qu'on ne la change pas.
            return null === $sequence || $counter->identity ? $alter : $alter.'
'.\sprintf('ALTER SEQUENCE %s AS bigint;', $sequence);
        }

        if ('bigint' === $width && $unsigned) {
            return null;
        }

        return \sprintf(
            'ALTER TABLE %s MODIFY %s BIGINT%s NOT NULL AUTO_INCREMENT;',
            SqlText::quoteIdentifier($engine, $table),
            SqlText::quoteIdentifier($engine, $column),
            $unsigned || 'bigint' === $width ? ' UNSIGNED' : '',
        );
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
                ? self::safely(static fn (): string => \sprintf('ALTER TABLE %1$s ALTER COLUMN %2$s TYPE uuid USING %2$s::uuid;', SqlText::quoteIdentifier($engine, $table->name), SqlText::quoteIdentifier($engine, $column->name)))
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
                $suggestion = self::safely(static fn (): string => \sprintf('ALTER TABLE %s ALTER COLUMN %s TYPE numeric(12,2);', SqlText::quoteIdentifier($engine, $table->name), SqlText::quoteIdentifier($engine, $column->name)));
            } elseif (null === $column->default && null === $column->comment && !$column->autoIncrement) {
                $suggestion = self::safely(static fn (): string => \sprintf('ALTER TABLE %s MODIFY %s DECIMAL(12,2) %s;', SqlText::quoteIdentifier($engine, $table->name), SqlText::quoteIdentifier($engine, $column->name), $column->nullable ? 'NULL' : 'NOT NULL'));
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
                suggestion: self::safely(static fn (): string => \sprintf('ALTER TABLE %s ALTER COLUMN %s TYPE %s;', SqlText::quoteIdentifier($engine, $table->name), SqlText::quoteIdentifier($engine, $name), self::plainType($referenced->type))),
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
    private function createIndex(Engine $engine, string $table, array $columns): ?string
    {
        return self::safely(static fn (): string => \sprintf(
            'CREATE INDEX %s ON %s (%s);',
            self::indexName($table, $columns),
            SqlText::quoteIdentifier($engine, $table),
            implode(', ', array_map(static fn (string $column): string => SqlText::quoteIdentifier($engine, $column), $columns)),
        ));
    }

    private function dropIndex(Engine $engine, string $table, string $index): ?string
    {
        return self::safely(static fn (): string => Engine::PostgreSql === $engine
            ? \sprintf('DROP INDEX %s;', SqlText::quoteIdentifier($engine, $index))
            : \sprintf('ALTER TABLE %s DROP INDEX %s;', SqlText::quoteIdentifier($engine, $table), SqlText::quoteIdentifier($engine, $index)));
    }

    /**
     * Une suggestion est du texte à copier : si l'un des noms qu'elle cite n'est pas sûr (saut de ligne, contrôle, texte invalide,
     * démesure), on n'en propose pas, plutôt que d'en écrire une qui pourrait agir autrement qu'annoncé.
     *
     * @param \Closure(): string $build
     */
    private static function safely(\Closure $build): ?string
    {
        try {
            return $build();
        } catch (UnsafeSqlNameException) {
            return null;
        }
    }

    /**
     * Un type lu dans le catalogue, recopié tel quel dans la suggestion seulement s'il a la forme d'un type usuel.
     */
    private static function plainType(string $type): string
    {
        if (1 !== preg_match('/\A[A-Za-z0-9_ ,()]{1,100}\z/', $type)) {
            throw new UnsafeSqlNameException('Unusual type.');
        }

        return $type;
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
}

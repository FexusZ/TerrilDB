<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

use App\Core\Connection\Engine;
use App\Core\Schema\PartitionMethod;
use App\Core\Schema\TableDefinition;

/**
 * Règles d'audit des tables partitionnées, volontairement prudentes : on ne juge que ce qui se vérifie sur ce que le catalogue donne.
 *
 *  - plage par date épuisée : une plage sur UNE colonne de date nue (pas une expression), sans partition fourre-tout (MAXVALUE ou
 *    DEFAULT), dont la dernière borne est passée depuis plus d'un jour (marge pour les fuseaux) : toute nouvelle ligne datée après
 *    cette borne est refusée par le moteur ;
 *  - trop de partitions : plus de `COUNT_ADVISED`, seuil d'avertissement de TerrilDB (MySQL et MariaDB plafonnent à 8 192, mais le
 *    coût de planification et d'ouverture de la table croît bien avant).
 */
final class PartitionAuditor
{
    public const COUNT_ADVISED = 1000;

    private const NAME_LIMIT = 63;

    /**
     * @return list<Finding>
     */
    public function audit(Engine $engine, TableDefinition $table, \DateTimeImmutable $now): array
    {
        $partitioning = $table->partitioning;
        if (null === $partitioning || !\in_array($engine, [Engine::MySql, Engine::MariaDb, Engine::PostgreSql], true)) {
            return [];
        }

        $findings = [];
        $last = $this->exhaustedBound($table, $now);
        if (null !== $last) {
            $findings[] = new Finding(
                FindingRule::PartitionRangeExhausted,
                Severity::Warning,
                $table->name,
                [$this->bareColumn($partitioning->expression) ?? ''],
                suggestion: $this->suggestion($engine, $table->name),
                detail: $last,
            );
        }
        if ($partitioning->count() > self::COUNT_ADVISED) {
            $findings[] = new Finding(FindingRule::TooManyPartitions, Severity::Info, $table->name, detail: (string) $partitioning->count());
        }

        return $findings;
    }

    /**
     * La dernière borne (littéral lu dans le catalogue) quand la plage ne couvre plus l'avenir, sinon null.
     */
    private function exhaustedBound(TableDefinition $table, \DateTimeImmutable $now): ?string
    {
        $partitioning = $table->partitioning;
        if (null === $partitioning || PartitionMethod::Range !== $partitioning->method) {
            return null;
        }
        $name = $this->bareColumn($partitioning->expression);
        $column = null === $name ? null : $table->column($name);
        if (null === $column || 1 !== preg_match('/^(date|datetime|timestamp)\b/i', $column->type)) {
            return null;
        }

        $latest = null;
        $latestText = null;
        foreach ($partitioning->partitions as $partition) {
            if ($partition->catchAll) {
                return null;
            }
            $date = null === $partition->upperBound ? null : $this->parse($partition->upperBound);
            if (null === $date) {
                // Une borne qu'on ne sait pas lire : on ne conclut pas.
                return null;
            }
            if (null === $latest || $date > $latest) {
                $latest = $date;
                $latestText = $partition->upperBound;
            }
        }

        return null !== $latest && $latest < $now->modify('-1 day') ? $latestText : null;
    }

    /**
     * Le nom de la colonne quand l'expression n'est QUE cette colonne (entre guillemets, ou non), sinon null.
     */
    private function bareColumn(string $expression): ?string
    {
        $expression = trim($expression);
        if (1 === preg_match('/^`((?:[^`]|``)+)`$/', $expression, $m)) {
            return str_replace('``', '`', $m[1]);
        }
        if (1 === preg_match('/^"((?:[^"]|"")+)"$/', $expression, $m)) {
            return str_replace('""', '"', $m[1]);
        }

        return 1 === preg_match('/^[A-Za-z_][A-Za-z0-9_$]*$/', $expression) ? $expression : null;
    }

    private function parse(string $literal): ?\DateTimeImmutable
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2}:\d{2}(?:\.\d+)?)?$/', $literal)) {
            return null;
        }
        try {
            return new \DateTimeImmutable($literal, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    private function suggestion(Engine $engine, string $table): ?string
    {
        if (Engine::PostgreSql === $engine) {
            $default = $this->quote($engine, $table.'_default');
            $parent = $this->quote($engine, $table);

            return null === $default || null === $parent || \strlen($table.'_default') > self::NAME_LIMIT
                ? null
                : \sprintf('CREATE TABLE %s PARTITION OF %s DEFAULT;', $default, $parent);
        }
        $parent = $this->quote($engine, $table);
        $max = $this->quote($engine, 'pmax');

        return null === $parent || null === $max ? null : \sprintf('ALTER TABLE %s ADD PARTITION (PARTITION %s VALUES LESS THAN (MAXVALUE));', $parent, $max);
    }

    /**
     * Un nom cité dans une suggestion à relire : jamais de caractère de contrôle ni de séparateur de ligne Unicode (le texte
     * pourrait masquer une ligne), sinon pas de suggestion du tout.
     */
    private function quote(Engine $engine, string $identifier): ?string
    {
        if ('' === $identifier || 1 === preg_match('/[\p{Cc}\p{Cf}\x{2028}\x{2029}\x{0085}]|[^\p{L}\p{N}\p{P}\p{S}\p{Zs} ]/u', $identifier)) {
            return null;
        }

        return Engine::PostgreSql === $engine ? '"'.str_replace('"', '""', $identifier).'"' : '`'.str_replace('`', '``', $identifier).'`';
    }
}

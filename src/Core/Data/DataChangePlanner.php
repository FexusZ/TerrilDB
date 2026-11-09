<?php

declare(strict_types=1);

namespace App\Core\Data;

use App\Core\Connection\Engine;
use App\Core\Data\Exception\InvalidDataChangeException;
use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;

/**
 * Écrit en SQL, pour un moteur, les lignes d'un brouillon. Les valeurs sont toujours des paramètres liés (le texte affiché
 * n'est jamais exécuté). Une ligne se retrouve par sa clé primaire ; sans clé (ClickHouse n'en a pas), par toutes ses valeurs
 * comparables. Dans les deux cas, les valeurs d'origine des colonnes modifiées servent aussi : une ligne changée entre-temps
 * n'est plus retrouvée.
 */
final class DataChangePlanner
{
    public const MAX_CHANGES = 500;

    private const NOT_COMPARABLE = '/json|xml|bytea|blob|binary|array|map\(|tuple|geometry|point|polygon/i';

    public function plan(Engine $engine, TableDefinition $table, DataDraft $draft): DataPlan
    {
        if (TableKind::Table !== $table->kind) {
            throw new InvalidDataChangeException('Only a table can be edited.');
        }
        $count = \count($draft->updates) + \count($draft->inserts) + \count($draft->deletes);
        if (0 === $count) {
            throw new InvalidDataChangeException('There is nothing to change.');
        }
        if ($count > self::MAX_CHANGES) {
            throw new InvalidDataChangeException(\sprintf('At most %d changes can be applied at once.', self::MAX_CHANGES));
        }

        $statements = [];
        foreach ($draft->updates as $update) {
            $statement = $this->update($engine, $table, $update);
            if (null !== $statement) {
                $statements[] = $statement;
            }
        }
        foreach ($draft->deletes as $delete) {
            $statements[] = $this->delete($engine, $table, $delete);
        }
        foreach ($draft->inserts as $insert) {
            $statements[] = $this->insert($engine, $table, $insert);
        }

        if ([] === $statements) {
            throw new InvalidDataChangeException('There is nothing to change.');
        }
        $clickHouse = Engine::ClickHouse === $engine;

        return new DataPlan($statements, !$clickHouse, $clickHouse);
    }

    private function update(Engine $engine, TableDefinition $table, RowUpdate $update): ?DataStatement
    {
        if ([] === $update->changes) {
            throw new InvalidDataChangeException('A modified row needs at least one changed column.');
        }
        // Une valeur remise telle qu'elle était n'est pas un changement (et MySQL ne compte pas une telle ligne comme touchée).
        $update = new RowUpdate($update->original, array_filter(
            $update->changes,
            fn (mixed $value, string|int $name): bool => !\array_key_exists($name, $update->original) || !self::same($update->original[$name], $value, $this->column($table, (string) $name)->type),
            \ARRAY_FILTER_USE_BOTH,
        ));
        if ([] === $update->changes) {
            return null;
        }
        $where = $this->whereColumns($table, $update->original, array_keys($update->changes));

        return $this->build($engine, $table, 'update', false, function (Binder $b) use ($table, $update, $where): array {
            $set = [];
            foreach ($update->changes as $name => $value) {
                $column = $this->column($table, (string) $name);
                $set[] = $b->quote((string) $name).' = '.(null === $value ? 'NULL' : $b->bind($value, $column->type));
            }

            return ['UPDATE '.$b->quote($table->name).' SET '.implode(', ', $set), $this->where($b, $table, $update->original, $where), 'ALTER TABLE '.$b->quote($table->name).' UPDATE '.implode(', ', $set)];
        }, $update->original, $where);
    }

    private function delete(Engine $engine, TableDefinition $table, RowDelete $delete): DataStatement
    {
        $where = $this->whereColumns($table, $delete->original, []);

        return $this->build($engine, $table, 'delete', true, fn (Binder $b): array => ['DELETE FROM '.$b->quote($table->name), $this->where($b, $table, $delete->original, $where), 'ALTER TABLE '.$b->quote($table->name).' DELETE'], $delete->original, $where);
    }

    private function insert(Engine $engine, TableDefinition $table, RowInsert $insert): DataStatement
    {
        if ([] === $insert->values) {
            throw new InvalidDataChangeException('A new row needs at least one value.');
        }
        foreach (array_keys($insert->values) as $name) {
            $this->column($table, (string) $name);
        }

        return $this->build($engine, $table, 'insert', false, function (Binder $b) use ($table, $insert, $engine): array {
            $names = [];
            $values = [];
            foreach ($insert->values as $name => $value) {
                $names[] = $b->quote((string) $name);
                $values[] = null === $value ? 'NULL' : $b->bind($value, $this->column($table, (string) $name)->type);
            }
            $sql = 'INSERT INTO '.$b->quote($table->name).' ('.implode(', ', $names).') '.(Engine::ClickHouse === $engine ? 'SELECT '.implode(', ', $values) : 'VALUES ('.implode(', ', $values).')');

            return [$sql, '', $sql];
        }, [], []);
    }

    /**
     * @param \Closure(Binder): array{string, string, string} $parts    [début relationnel, WHERE, début ClickHouse]
     * @param array<string, bool|float|int|string|null>       $original
     * @param list<string>                                    $where
     */
    private function build(Engine $engine, TableDefinition $table, string $operation, bool $destructive, \Closure $parts, array $original, array $where): DataStatement
    {
        $run = static function (bool $literal) use ($engine, $parts): array {
            $binder = new Binder($engine, $literal);
            [$relational, $clause, $clickHouse] = $parts($binder);
            $head = Engine::ClickHouse === $engine ? $clickHouse : $relational;

            return [$head.$clause, $binder];
        };
        [$sql, $bound] = $run(false);
        [$display] = $run(true);

        $matchSql = null;
        $matchParams = [];
        if ('insert' !== $operation) {
            $matcher = new Binder($engine, false);
            $clause = $this->where($matcher, $table, $original, $where);
            $matchSql = 'SELECT '.(Engine::ClickHouse === $engine ? 'count()' : 'count(*)').' FROM '.$matcher->quote($table->name).$clause;
            $matchParams = $matcher->params();
        }

        return new DataStatement($sql, $bound->params(), $display, $operation, $table->name, $destructive, $matchSql, $matchParams);
    }

    /**
     * @param array<string, bool|float|int|string|null> $original
     * @param list<string>                              $where
     */
    private function where(Binder $b, TableDefinition $table, array $original, array $where): string
    {
        $parts = [];
        foreach ($where as $name) {
            $value = $original[$name];
            $parts[] = $b->quote($name).(null === $value ? ' IS NULL' : ' = '.$b->bind($value, $this->column($table, $name)->type));
        }

        return ' WHERE '.implode(' AND ', $parts);
    }

    /**
     * Les colonnes qui retrouvent la ligne : sa clé primaire (puis les colonnes modifiées, pour vérifier qu'elles n'ont pas
     * bougé), ou à défaut toutes ses colonnes comparables.
     *
     * @param array<string, bool|float|int|string|null> $original
     * @param list<int|string>                          $changed
     *
     * @return non-empty-list<string>
     */
    private function whereColumns(TableDefinition $table, array $original, array $changed): array
    {
        foreach (array_keys($original) as $name) {
            $this->column($table, (string) $name);
        }
        $primary = $table->primaryKey();
        $names = [];
        if (null !== $primary) {
            $names = $primary->columns;
            foreach ($changed as $name) {
                $names[] = (string) $name;
            }
        } else {
            foreach ($table->columns as $column) {
                $names[] = $column->name;
            }
        }

        $where = [];
        foreach (array_unique($names) as $name) {
            $column = $this->column($table, $name);
            $isKey = null !== $primary && \in_array($name, $primary->columns, true);
            if (!$isKey && 1 === preg_match(self::NOT_COMPARABLE, $column->type)) {
                continue;
            }
            if (!\array_key_exists($name, $original)) {
                throw new InvalidDataChangeException(\sprintf('The current value of the column "%s" is needed to find the row.', $name));
            }
            $where[] = $name;
        }
        if ([] === $where) {
            throw new InvalidDataChangeException('The row cannot be identified.');
        }

        return $where;
    }

    private const NUMERIC_TYPE = '/int|numeric|decimal|float|double|real|number/i';

    /** Deux écritures de la même valeur : égales, ou, pour une colonne numérique, le même nombre (« 5.00 », « 5.0 », « +5 »). */
    private static function same(mixed $original, mixed $value, string $type): bool
    {
        if ($original === $value) {
            return true;
        }
        if (!\is_scalar($original) || !\is_scalar($value) || \is_bool($original) || \is_bool($value) || 1 !== preg_match(self::NUMERIC_TYPE, $type)) {
            return false;
        }
        $a = self::number((string) $original);

        return null !== $a && $a === self::number((string) $value);
    }

    /** Un nombre décimal ramené à sa forme canonique, en texte (aucun passage par un flottant : un grand entier ne perd rien). */
    private static function number(string $text): ?string
    {
        if (1 !== preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', trim($text), $m)) {
            return null;
        }
        $integer = ltrim($m[2], '0');
        $integer = '' === $integer ? '0' : $integer;
        $fraction = rtrim($m[3] ?? '', '0');
        $negative = '-' === $m[1] && ('0' !== $integer || '' !== $fraction);

        return ($negative ? '-' : '').$integer.('' === $fraction ? '' : '.'.$fraction);
    }

    private function column(TableDefinition $table, string $name): ColumnDefinition
    {
        return $table->column($name) ?? throw new InvalidDataChangeException(\sprintf('The column "%s" does not exist.', $name));
    }
}

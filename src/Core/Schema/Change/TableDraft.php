<?php

declare(strict_types=1);

namespace App\Core\Schema\Change;

use App\Core\Schema\Change\Exception\InvalidSchemaChangeException;

/**
 * La table telle qu'on la veut, d'un bloc : c'est le serveur qui en déduit les instructions en la comparant à la table réelle.
 * Rien n'est jamais exécuté d'après du SQL reçu du client.
 */
final readonly class TableDraft
{
    /**
     * @param list<ColumnDraft>     $columns
     * @param list<IndexDraft>      $indexes
     * @param list<ForeignKeyDraft> $foreignKeys
     */
    public function __construct(
        public string $name,
        public array $columns,
        public array $indexes,
        public array $foreignKeys,
    ) {
        NameRule::check($name, 'table');
        if ([] === $columns) {
            throw new InvalidSchemaChangeException('A table needs at least one column.');
        }
        $names = array_map(static fn (ColumnDraft $c): string => $c->name, $columns);
        if (\count(array_unique($names)) !== \count($names)) {
            throw new InvalidSchemaChangeException('Two columns cannot have the same name.');
        }
        $indexNames = array_map(static fn (IndexDraft $i): string => $i->name, array_filter($indexes, static fn (IndexDraft $i): bool => !$i->primary));
        if (\count(array_unique($indexNames)) !== \count($indexNames)) {
            throw new InvalidSchemaChangeException('Two indexes cannot have the same name.');
        }
        if (\count(array_filter($indexes, static fn (IndexDraft $i): bool => $i->primary)) > 1) {
            throw new InvalidSchemaChangeException('A table has one primary key.');
        }
        foreach ($indexes as $index) {
            foreach ($index->columns as $column) {
                if (!\in_array($column, $names, true)) {
                    throw new InvalidSchemaChangeException('An index refers to a column that does not exist.');
                }
            }
        }
        foreach ($foreignKeys as $foreignKey) {
            foreach ($foreignKey->columns as $column) {
                if (!\in_array($column, $names, true)) {
                    throw new InvalidSchemaChangeException('A foreign key refers to a column that does not exist.');
                }
            }
        }
        $fkNames = array_map(static fn (ForeignKeyDraft $f): string => $f->name, $foreignKeys);
        if (\count(array_unique($fkNames)) !== \count($fkNames)) {
            throw new InvalidSchemaChangeException('Two foreign keys cannot have the same name.');
        }
    }

    public function withName(string $name): self
    {
        return new self($name, $this->columns, $this->indexes, $this->foreignKeys);
    }

    /**
     * @param list<ColumnDraft> $columns
     */
    public function withColumns(array $columns): self
    {
        return new self($this->name, $columns, $this->indexes, $this->foreignKeys);
    }

    /**
     * @param list<IndexDraft> $indexes
     */
    public function withIndexes(array $indexes): self
    {
        return new self($this->name, $this->columns, $indexes, $this->foreignKeys);
    }

    /**
     * @param list<ForeignKeyDraft> $foreignKeys
     */
    public function withForeignKeys(array $foreignKeys): self
    {
        return new self($this->name, $this->columns, $this->indexes, $foreignKeys);
    }

    public function primaryKey(): ?IndexDraft
    {
        foreach ($this->indexes as $index) {
            if ($index->primary) {
                return $index;
            }
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Json;

use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\DatabaseSummary;
use App\Core\Schema\ForeignKeyDefinition;
use App\Core\Schema\IndexDefinition;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableSummary;

final class SchemaPresenter
{
    /**
     * @return array<string, bool|string>
     */
    public static function database(DatabaseSummary $database): array
    {
        return ['name' => $database->name, 'system' => $database->system];
    }

    /**
     * @return array<string, int|string|null>
     */
    public static function summary(TableSummary $table): array
    {
        return ['name' => $table->name, 'kind' => $table->kind->value, 'estimatedRows' => $table->estimatedRows];
    }

    /**
     * @return array<string, mixed>
     */
    public static function table(TableDefinition $table): array
    {
        return [
            'name' => $table->name,
            'kind' => $table->kind->value,
            'estimatedRows' => $table->estimatedRows,
            'viewDefinition' => $table->viewDefinition,
            'columns' => array_map(static fn (ColumnDefinition $c): array => [
                'name' => $c->name,
                'type' => $c->type,
                'nullable' => $c->nullable,
                'default' => $c->default,
                'autoIncrement' => $c->autoIncrement,
                'comment' => $c->comment,
            ], $table->columns),
            'indexes' => array_map(static fn (IndexDefinition $i): array => [
                'name' => $i->name,
                'columns' => $i->columns,
                'unique' => $i->unique,
                'primary' => $i->primary,
            ], $table->indexes),
            'foreignKeys' => array_map(static fn (ForeignKeyDefinition $f): array => [
                'name' => $f->name,
                'columns' => $f->columns,
                'referencedTable' => $f->referencedTable,
                'referencedColumns' => $f->referencedColumns,
                'onUpdate' => $f->onUpdate->value,
                'onDelete' => $f->onDelete->value,
            ], $table->foreignKeys),
        ];
    }
}

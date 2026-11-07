<?php

declare(strict_types=1);

namespace App\Http\Json;

use App\Core\Schema\Change\ColumnDraft;
use App\Core\Schema\Change\Exception\InvalidSchemaChangeException;
use App\Core\Schema\Change\ForeignKeyDraft;
use App\Core\Schema\Change\IndexDraft;
use App\Core\Schema\Change\TableDraft;
use App\Core\Schema\ReferentialAction;

/**
 * Lit le brouillon de table envoyé par l'interface. Chaque champ est typé ; la validité des noms, des types et des valeurs par
 * défaut est vérifiée par le cœur (`ColumnDraft` & co), pas ici.
 */
final class TableDraftInput
{
    public static function from(JsonInput $draft): TableDraft
    {
        return new TableDraft(
            $draft->string('name'),
            array_map(static fn (JsonInput $c): ColumnDraft => new ColumnDraft(
                $c->optionalString('originalName'),
                $c->string('name'),
                $c->string('type'),
                $c->bool('nullable'),
                $c->optionalString('default'),
                $c->bool('autoIncrement', false),
            ), $draft->objects('columns')),
            array_map(static fn (JsonInput $i): IndexDraft => new IndexDraft(
                $i->optionalString('originalName'),
                $i->string('name'),
                $i->stringList('columns'),
                $i->bool('unique'),
                $i->bool('primary', false),
                $i->optionalString('kind'),
                $i->optionalInt('granularity'),
            ), $draft->objects('indexes')),
            array_map(static fn (JsonInput $f): ForeignKeyDraft => new ForeignKeyDraft(
                $f->optionalString('originalName'),
                $f->string('name'),
                $f->stringList('columns'),
                $f->string('referencedTable'),
                $f->stringList('referencedColumns'),
                self::action($f->string('onUpdate')),
                self::action($f->string('onDelete')),
            ), $draft->objects('foreignKeys')),
        );
    }

    private static function action(string $value): ReferentialAction
    {
        return ReferentialAction::tryFrom($value) ?? throw new InvalidSchemaChangeException('This referential action is not supported.');
    }
}

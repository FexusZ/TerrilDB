<?php

declare(strict_types=1);

namespace App\Core\Export;

use App\Core\Query\QueryResult;

/**
 * Exporte un résultat en JSON : un tableau d'objets, un par ligne, dont les clés sont les colonnes. Deux colonnes de
 * même nom (une jointure sans alias) reçoivent un suffixe pour ne pas s'écraser.
 */
final class JsonExporter
{
    public function export(QueryResult $result): string
    {
        if (!$result->returnsRows()) {
            throw new \InvalidArgumentException('Only a result that returns rows can be exported.');
        }

        $keys = [];
        $seen = [];
        foreach ($result->columns as $column) {
            $count = $seen[$column] = ($seen[$column] ?? 0) + 1;
            $keys[] = 1 === $count ? $column : $column.'_'.$count;
        }

        $objects = [];
        foreach ($result->rows as $row) {
            $objects[] = array_combine($keys, $row);
        }

        return json_encode($objects, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n";
    }
}

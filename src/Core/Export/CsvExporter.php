<?php

declare(strict_types=1);

namespace App\Core\Export;

use App\Core\Query\QueryResult;

/**
 * Exporte un résultat en CSV (RFC 4180 : séparateur virgule, fin de ligne CRLF, UTF-8).
 *
 * Un tableur interprète comme une formule toute cellule qui commence par `=`, `+`, `-`, `@`, une tabulation ou un
 * retour chariot : une donnée de la base pourrait alors exécuter une commande chez celui qui ouvre le fichier. Ces
 * cellules sont donc précédées d'une apostrophe, sauf les nombres (un nombre négatif n'est pas une formule).
 */
final class CsvExporter
{
    private const BOM = "\xEF\xBB\xBF";
    private const NUMERIC = '/^[+-]?\d+(\.\d+)?([eE][+-]?\d+)?$/D';

    /**
     * @param bool $bom ajoute la marque d'ordre des octets, que certains tableurs exigent pour lire l'UTF-8
     */
    public function export(QueryResult $result, bool $bom = false): string
    {
        if (!$result->returnsRows()) {
            throw new \InvalidArgumentException('Only a result that returns rows can be exported.');
        }

        $lines = [implode(',', array_map($this->cell(...), $result->columns))];
        foreach ($result->rows as $row) {
            $lines[] = implode(',', array_map($this->cell(...), $row));
        }

        return ($bom ? self::BOM : '').implode("\r\n", $lines)."\r\n";
    }

    private function cell(bool|float|int|string|null $value): string
    {
        if (null === $value) {
            return '';
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_int($value)) {
            return (string) $value;
        }
        if (\is_float($value)) {
            return rtrim(rtrim(\sprintf('%.15F', $value), '0'), '.');
        }

        if ('' !== $value && str_contains("=+-@\t\r", $value[0]) && 1 !== preg_match(self::NUMERIC, $value)) {
            $value = "'".$value;
        }

        return 1 === preg_match('/[,"\r\n]|^ | $/', $value) ? '"'.str_replace('"', '""', $value).'"' : $value;
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Export;

use App\Core\Query\QueryResult;

/**
 * Les formats d'export d'un résultat, communs à l'interface, à l'API à jetons et au MCP.
 */
enum ExportFormat: string
{
    case Csv = 'csv';
    case Json = 'json';

    public function render(QueryResult $result, bool $bom = false): string
    {
        return match ($this) {
            self::Csv => (new CsvExporter())->export($result, $bom),
            self::Json => (new JsonExporter())->export($result),
        };
    }

    public function contentType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv; charset=utf-8',
            self::Json => 'application/json; charset=utf-8',
        };
    }
}

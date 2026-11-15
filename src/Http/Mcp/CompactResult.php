<?php

declare(strict_types=1);

namespace App\Http\Mcp;

use App\Core\Export\CsvExporter;
use App\Core\Query\QueryResult;
use App\Http\Json\ResultPresenter;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

/**
 * Le résultat d'une requête sous forme compacte : du texte seulement. Un résultat rendu sous forme de tableau PHP
 * est doublé par le SDK en `structuredContent` ; construire soi-même le `CallToolResult` l'évite.
 */
final class CompactResult
{
    public static function render(QueryResult $result, ResultFormat $format): CallToolResult
    {
        if (ResultFormat::Json === $format || !$result->returnsRows()) {
            return new CallToolResult([new TextContent(self::json(ResultPresenter::present($result)))]);
        }

        return new CallToolResult([
            new TextContent((new CsvExporter())->export($result)),
            new TextContent(self::json((null === $result->totalRows ? [] : ['totalRows' => $result->totalRows]) + ['rowCount' => $result->rowCount(), 'truncated' => $result->truncated, 'durationMs' => $result->durationMs])),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data): string
    {
        return json_encode($data, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);
    }
}

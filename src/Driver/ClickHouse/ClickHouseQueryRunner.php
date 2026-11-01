<?php

declare(strict_types=1);

namespace App\Driver\ClickHouse;

use App\Core\Guard\StatementKind;
use App\Core\Query\CellValue;
use App\Core\Query\Exception\QueryFailedException;
use App\Core\Query\Exception\QueryTimeoutException;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryOptions;
use App\Core\Query\QueryResult;
use App\Core\Query\QueryRunner;
use App\Driver\PdoRows;

/**
 * Exécution sur ClickHouse par son interface HTTP. En lecture seule, le réglage `readonly=1` fait imposer la lecture
 * par le moteur (il refuse écritures, DDL et changements de réglages ; c'est lui qui fait foi). Le délai est un
 * `max_execution_time` du moteur. ClickHouse n'a pas de transaction : une écriture est immédiate.
 *
 * Pour ne pas charger un résultat énorme, le moteur s'arrête juste après `maxRows + 1` lignes (`result_overflow_mode=break`,
 * par blocs : la taille de bloc est ramenée à la limite) ; les lignes en trop sont écartées ici.
 */
final readonly class ClickHouseQueryRunner implements QueryRunner
{
    private const TIMEOUT_CODE = 159;
    // Taille de bloc par défaut de ClickHouse : au-delà, inutile de la modifier.
    private const DEFAULT_BLOCK_SIZE = 65_409;

    public function __construct(private ClickHouseHttp $http)
    {
    }

    public function run(string $sql, StatementKind $kind, QueryOptions $options): QueryResult
    {
        $started = hrtime(true);
        $settings = ['max_execution_time' => $options->timeoutMs / 1000];
        if (QueryMode::ReadOnly === $options->mode) {
            $settings['readonly'] = 1;
        }
        if (StatementKind::Read === $kind || StatementKind::Inspect === $kind) {
            $settings['max_result_rows'] = $options->maxRows + 1;
            $settings['result_overflow_mode'] = 'break';
            $settings['max_block_size'] = min($options->maxRows + 1, self::DEFAULT_BLOCK_SIZE);
        }

        try {
            $response = $this->http->query($sql, $settings, timeoutSeconds: $options->timeoutMs / 1000);
        } catch (ClickHouseException $e) {
            throw self::TIMEOUT_CODE === $e->engineCode ? QueryTimeoutException::afterMilliseconds($options->timeoutMs, $e) : new QueryFailedException($e->getMessage(), previous: $e);
        }

        if ([] === $response->columns) {
            return QueryResult::affected($response->writtenRows, PdoRows::elapsed($started));
        }

        $truncated = \count($response->rows) > $options->maxRows;
        $rows = [];
        foreach (\array_slice($response->rows, 0, $options->maxRows) as $row) {
            $rows[] = array_map(self::cell(...), $row);
        }

        return QueryResult::rows($response->columns, $rows, $truncated, PdoRows::elapsed($started));
    }

    private static function cell(mixed $value): bool|float|int|string|null
    {
        if (\is_array($value)) {
            return json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '[unsupported]';
        }

        return CellValue::normalize($value);
    }
}

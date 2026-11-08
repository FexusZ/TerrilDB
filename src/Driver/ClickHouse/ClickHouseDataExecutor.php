<?php

declare(strict_types=1);

namespace App\Driver\ClickHouse;

use App\Core\Data\DataExecutor;
use App\Core\Data\DataPlan;
use App\Core\Data\DataStatement;
use App\Core\Data\Exception\DataChangeFailedException;

/**
 * ClickHouse n'a ni transaction ni écriture immédiate : un ajout est visible tout de suite, une modification ou une suppression
 * est une « mutation » que le moteur applique en arrière-plan. Chaque instruction part seule.
 */
final readonly class ClickHouseDataExecutor implements DataExecutor
{
    private const TIMEOUT_SECONDS = 60.0;

    public function __construct(private ClickHouseHttp $http)
    {
    }

    public function matching(DataStatement $statement): int
    {
        try {
            $rows = $this->http->query($statement->matchSql ?? '', params: self::strings($statement->matchParams), timeoutSeconds: self::TIMEOUT_SECONDS)->rows;
        } catch (ClickHouseException $e) {
            throw new DataChangeFailedException($e->getMessage(), 0, $statement, $e);
        }
        $first = $rows[0][0] ?? 0;

        return is_numeric($first) ? (int) $first : 0;
    }

    public function execute(DataPlan $plan): void
    {
        $applied = 0;
        foreach ($plan->statements as $statement) {
            try {
                $this->http->query($statement->sql, params: self::strings($statement->params), timeoutSeconds: self::TIMEOUT_SECONDS);
            } catch (ClickHouseException $e) {
                throw new DataChangeFailedException($e->getMessage(), $applied, $statement, $e);
            }
            ++$applied;
        }
    }

    /**
     * @param array<int|string, bool|float|int|string> $params
     *
     * @return array<string, string>
     */
    private static function strings(array $params): array
    {
        $out = [];
        foreach ($params as $name => $value) {
            $out[(string) $name] = (string) $value;
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace App\Driver\ClickHouse;

use App\Core\Schema\Change\DdlExecutor;
use App\Core\Schema\Change\Exception\SchemaChangeFailedException;

/**
 * ClickHouse n'a pas de transaction : chaque instruction part seule et reste appliquée même si la suivante échoue.
 */
final readonly class ClickHouseDdlExecutor implements DdlExecutor
{
    private const TIMEOUT_SECONDS = 60.0;

    public function __construct(private ClickHouseHttp $http)
    {
    }

    public function execute(array $statements, bool $transactional): void
    {
        $applied = [];
        foreach ($statements as $statement) {
            try {
                $this->http->query($statement->sql, timeoutSeconds: self::TIMEOUT_SECONDS);
            } catch (ClickHouseException $e) {
                throw new SchemaChangeFailedException($e->getMessage(), $applied, $statement, $e);
            }
            $applied[] = $statement;
        }
    }
}

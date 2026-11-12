<?php

declare(strict_types=1);

namespace App\Tests\Driver\PostgreSql;

use App\Driver\PostgreSql\PostgreSqlOperations;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class PostgreSqlOperationsTest extends TestCase
{
    public function testTheUpkeepReadIsBoundedOnAHugeSchema(): void
    {
        /** @var \ArrayObject<int, string> $sql */
        $sql = new \ArrayObject();
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(static function (string $statement) use ($sql): array {
            $sql->append($statement);

            return [];
        });

        (new PostgreSqlOperations($connection))->upkeep();

        self::assertCount(1, $sql);
        self::assertMatchesRegularExpression('/LIMIT\s+1000\b/', (string) $sql[0]);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Driver\MySql;

use App\Driver\MySql\MySqlOperations;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class MySqlOperationsTest extends TestCase
{
    public function testTheUpkeepReadIsBoundedOnAHugeSchema(): void
    {
        /** @var \ArrayObject<int, string> $sql */
        $sql = new \ArrayObject();
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn('shop');
        $connection->method('fetchFirstColumn')->willReturn([]);
        $connection->method('fetchAllAssociative')->willReturnCallback(static function (string $statement) use ($sql): array {
            $sql->append($statement);

            return [];
        });

        (new MySqlOperations($connection))->upkeep();

        self::assertCount(1, $sql);
        self::assertMatchesRegularExpression('/LIMIT\s+1000\b/', (string) $sql[0]);
    }
}

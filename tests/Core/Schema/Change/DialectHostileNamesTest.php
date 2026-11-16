<?php

declare(strict_types=1);

namespace App\Tests\Core\Schema\Change;

use App\Core\Schema\Change\Dialect\ClickHouseDdlDialect;
use App\Core\Schema\Change\Dialect\DdlDialect;
use App\Core\Schema\Change\Dialect\MySqlDdlDialect;
use App\Core\Schema\Change\Dialect\PostgreSqlDdlDialect;
use App\Core\Schema\Change\Exception\InvalidSchemaChangeException;
use App\Core\Schema\Change\Operation\DropColumn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Un nom qui vient de la base cible (table lue par l'introspection) ne devient jamais du SQL montré s'il contient un séparateur
 * de ligne ou un contrôle : le dialecte refuse de le citer.
 */
final class DialectHostileNamesTest extends TestCase
{
    /**
     * @return iterable<string, array{DdlDialect, string}>
     */
    public static function cases(): iterable
    {
        $names = ["x\nDROP DATABASE y", "x\rDROP", "x\u{85}DROP", "x\u{2028}DROP", "x\u{2029}DROP", "x\0DROP", "x\u{202E}y"];
        foreach (['mysql' => new MySqlDdlDialect(), 'postgresql' => new PostgreSqlDdlDialect(), 'clickhouse' => new ClickHouseDdlDialect()] as $engine => $dialect) {
            foreach ($names as $i => $name) {
                yield $engine.' '.$i => [$dialect, $name];
            }
        }
    }

    #[DataProvider('cases')]
    public function testHostileTableNameIsRefused(DdlDialect $dialect, string $name): void
    {
        $this->expectException(InvalidSchemaChangeException::class);
        $dialect->statements($name, [new DropColumn('a')]);
    }

    #[DataProvider('cases')]
    public function testHostileColumnNameIsRefused(DdlDialect $dialect, string $name): void
    {
        $this->expectException(InvalidSchemaChangeException::class);
        $dialect->statements('orders', [new DropColumn($name)]);
    }

    public function testQuotesAndCommentMarkersStayInsideTheIdentifier(): void
    {
        $sql = (new MySqlDdlDialect())->statements('o`; DROP TABLE t; -- ', [new DropColumn('a*/b')]);
        self::assertSame('ALTER TABLE `o``; DROP TABLE t; -- ` DROP COLUMN `a*/b`', $sql[0]->sql);
        $sql = (new PostgreSqlDdlDialect())->statements('o"; DROP TABLE t; -- ', [new DropColumn('a')]);
        self::assertSame('ALTER TABLE "o""; DROP TABLE t; -- " DROP COLUMN "a"', $sql[0]->sql);
    }
}

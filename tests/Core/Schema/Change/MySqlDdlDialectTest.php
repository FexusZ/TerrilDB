<?php

declare(strict_types=1);

namespace App\Tests\Core\Schema\Change;

use App\Core\Schema\Change\ColumnDraft;
use App\Core\Schema\Change\Dialect\MySqlDdlDialect;
use App\Core\Schema\Change\Exception\InvalidSchemaChangeException;
use App\Core\Schema\Change\ForeignKeyDraft;
use App\Core\Schema\Change\IndexDraft;
use App\Core\Schema\Change\Operation\AddColumn;
use App\Core\Schema\Change\Operation\AddForeignKey;
use App\Core\Schema\Change\Operation\AddIndex;
use App\Core\Schema\Change\Operation\DropColumn;
use App\Core\Schema\Change\Operation\DropForeignKey;
use App\Core\Schema\Change\Operation\DropIndex;
use App\Core\Schema\Change\Operation\DropTable;
use App\Core\Schema\Change\Operation\ModifyColumn;
use App\Core\Schema\Change\Operation\RenameColumn;
use App\Core\Schema\Change\Operation\RenameIndex;
use App\Core\Schema\Change\Operation\RenameTable;
use App\Core\Schema\Change\Operation\SetPrimaryKey;
use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\IndexDefinition;
use App\Core\Schema\ReferentialAction;
use PHPUnit\Framework\TestCase;

final class MySqlDdlDialectTest extends TestCase
{
    /**
     * @param list<object> $operations
     *
     * @return list<string>
     */
    private static function sql(array $operations): array
    {
        return array_map(static fn ($statement): string => $statement->sql, (new MySqlDdlDialect())->statements('orders', $operations));
    }

    public function testQuotesWithBackticksAndDoublesTheOnesInsideANameAndEscapesBackslashesInStrings(): void
    {
        self::assertSame(
            ['ALTER TABLE `orders` ADD COLUMN `we``ird` varchar(20) NULL DEFAULT \'a\\\\b\'\'c\''],
            self::sql([new AddColumn(new ColumnDraft(null, 'we`ird', 'varchar(20)', true, "a\\b'c", false))]),
        );
    }

    public function testAddsAColumn(): void
    {
        self::assertSame(
            ['ALTER TABLE `orders` ADD COLUMN `status` varchar(20) NOT NULL DEFAULT \'new\''],
            self::sql([new AddColumn(new ColumnDraft(null, 'status', 'varchar(20)', false, 'new', false))]),
        );
        self::assertSame(
            ['ALTER TABLE `orders` ADD COLUMN `n` int NOT NULL DEFAULT 0 AUTO_INCREMENT'],
            self::sql([new AddColumn(new ColumnDraft(null, 'n', 'int', false, '0', true))]),
        );
    }

    public function testExpressionDefaultsThatMysqlOnlyAcceptsInParentheses(): void
    {
        self::assertSame(['ALTER TABLE `orders` ADD COLUMN `c` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP'], self::sql([new AddColumn(new ColumnDraft(null, 'c', 'datetime', false, 'now()', false))]));
        self::assertSame(['ALTER TABLE `orders` ADD COLUMN `u` char(36) NOT NULL DEFAULT (uuid())'], self::sql([new AddColumn(new ColumnDraft(null, 'u', 'char(36)', false, 'uuid()', false))]));
        self::assertSame(['ALTER TABLE `orders` ADD COLUMN `b` tinyint(1) NOT NULL DEFAULT TRUE'], self::sql([new AddColumn(new ColumnDraft(null, 'b', 'tinyint(1)', false, 'true', false))]));
    }

    public function testRefusesASequenceDefault(): void
    {
        $this->expectException(InvalidSchemaChangeException::class);

        self::sql([new AddColumn(new ColumnDraft(null, 'c', 'int', false, "nextval('s'::regclass)", false))]);
    }

    public function testDropsAndRenamesColumns(): void
    {
        self::assertSame(['ALTER TABLE `orders` DROP COLUMN `note`'], self::sql([new DropColumn('note')]));
        self::assertSame(['ALTER TABLE `orders` RENAME COLUMN `a` TO `b`'], self::sql([new RenameColumn('a', 'b')]));
    }

    public function testModifyingAColumnRestatesItsWholeDefinitionAndKeepsItsComment(): void
    {
        $before = new ColumnDefinition('status', 'varchar(20)', false, 'new', false, 'État de la commande');
        $after = new ColumnDraft('status', 'status', 'varchar(40)', true, null, false);

        self::assertSame(
            ['ALTER TABLE `orders` MODIFY COLUMN `status` varchar(40) NULL COMMENT \'État de la commande\''],
            self::sql([new ModifyColumn($before, $after, true, true, true, false)]),
        );
    }

    public function testPrimaryKey(): void
    {
        $old = new IndexDefinition('PRIMARY', ['id'], true, true);

        self::assertSame(
            ['ALTER TABLE `orders` DROP PRIMARY KEY', 'ALTER TABLE `orders` ADD PRIMARY KEY (`id`, `customer_id`)'],
            self::sql([new SetPrimaryKey($old, new IndexDraft(null, 'PRIMARY', ['id', 'customer_id'], true, true, null, null))]),
        );
    }

    public function testAColumnThatIsAutoIncrementAndNewTravelsWithItsPrimaryKey(): void
    {
        self::assertSame(
            ['ALTER TABLE `orders` ADD COLUMN `id` int NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (`id`)'],
            self::sql([
                new AddColumn(new ColumnDraft(null, 'id', 'int', false, null, true)),
                new SetPrimaryKey(null, new IndexDraft(null, 'PRIMARY', ['id'], true, true, null, null)),
            ]),
        );
    }

    public function testIndexes(): void
    {
        self::assertSame(['CREATE INDEX `idx_s` ON `orders` (`status`)'], self::sql([new AddIndex(new IndexDraft(null, 'idx_s', ['status'], false, false, null, null))]));
        self::assertSame(['CREATE UNIQUE INDEX `u` ON `orders` (`a`, `b`)'], self::sql([new AddIndex(new IndexDraft(null, 'u', ['a', 'b'], true, false, null, null))]));
        self::assertSame(['DROP INDEX `idx_s` ON `orders`'], self::sql([new DropIndex('idx_s', null)]));
        self::assertSame(['ALTER TABLE `orders` RENAME INDEX `a` TO `b`'], self::sql([new RenameIndex('a', 'b')]));
    }

    public function testForeignKeys(): void
    {
        $fk = new ForeignKeyDraft(null, 'fk_c', ['customer_id'], 'customers', ['id'], ReferentialAction::Cascade, ReferentialAction::Restrict);

        self::assertSame(
            ['ALTER TABLE `orders` ADD CONSTRAINT `fk_c` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT'],
            self::sql([new AddForeignKey($fk)]),
        );
        self::assertSame(['ALTER TABLE `orders` DROP FOREIGN KEY `fk_c`'], self::sql([new DropForeignKey('fk_c')]));
    }

    public function testRefusesSetDefaultWhichInnodbDoesNotKnow(): void
    {
        $this->expectException(InvalidSchemaChangeException::class);

        self::sql([new AddForeignKey(new ForeignKeyDraft(null, 'fk', ['a'], 't', ['id'], ReferentialAction::NoAction, ReferentialAction::SetDefault))]);
    }

    public function testRenamesAndDropsTheTable(): void
    {
        self::assertSame(['RENAME TABLE `orders` TO `sales`'], self::sql([new RenameTable('orders', 'sales')]));
        self::assertSame(['DROP TABLE `orders`'], self::sql([new DropTable('orders')]));
    }

    public function testStatementsAreNotTransactionalBecauseMysqlCommitsEachDdl(): void
    {
        self::assertFalse((new MySqlDdlDialect())->transactional());
    }
}

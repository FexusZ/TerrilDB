<?php

declare(strict_types=1);

namespace App\Tests\Http\Json;

use App\Core\Schema\Change\Algorithm\AlgorithmAdvice;
use App\Core\Schema\Change\Algorithm\AlterAlgorithm;
use App\Core\Schema\Change\ChangePlan;
use App\Core\Schema\Change\DdlStatement;
use App\Http\Json\AlgorithmPresenter;
use PHPUnit\Framework\TestCase;

final class AlgorithmPresenterTest extends TestCase
{
    public function testEachStatementKeepsItsFieldsAndGainsTheAlgorithmOnes(): void
    {
        $plan = new ChangePlan([
            new DdlStatement('ALTER TABLE `t` ADD COLUMN `x` int NULL', 'add_column', 'x', false),
            new DdlStatement('ALTER TABLE `t` MODIFY COLUMN `n` bigint NULL', 'modify_column', 'n', true),
        ], false);
        $advice = [
            new AlgorithmAdvice(AlterAlgorithm::Instant, false, false, null),
            new AlgorithmAdvice(AlterAlgorithm::Copy, true, true, 123),
        ];

        self::assertSame([
            ['sql' => 'ALTER TABLE `t` ADD COLUMN `x` int NULL', 'operation' => 'add_column', 'subject' => 'x', 'destructive' => false, 'algorithm' => 'instant', 'blocksWrites' => false, 'copiesTable' => false, 'estimatedExtraBytes' => null, 'algorithmReason' => null],
            ['sql' => 'ALTER TABLE `t` MODIFY COLUMN `n` bigint NULL', 'operation' => 'modify_column', 'subject' => 'n', 'destructive' => true, 'algorithm' => 'copy', 'blocksWrites' => true, 'copiesTable' => true, 'estimatedExtraBytes' => 123, 'algorithmReason' => null],
        ], AlgorithmPresenter::statements($plan, $advice));
    }

    public function testAnUnknownAlgorithmSaysWhy(): void
    {
        $plan = new ChangePlan([new DdlStatement('ALTER TABLE `t` ADD COLUMN `x` int NULL', 'add_column', 'x', false)], false);

        $statement = AlgorithmPresenter::statements($plan, [AlgorithmAdvice::unknown('unverified_version')])[0];

        self::assertSame('unknown', $statement['algorithm']);
        self::assertNull($statement['blocksWrites']);
        self::assertNull($statement['copiesTable']);
        self::assertSame('unverified_version', $statement['algorithmReason']);
    }

    public function testWithoutAdviceTheStatementsAreUnknown(): void
    {
        $plan = new ChangePlan([new DdlStatement('DROP TABLE `t`', 'drop_table', 't', true)], false);

        self::assertSame('unknown', AlgorithmPresenter::statements($plan, [])[0]['algorithm']);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Core\Schema;

use App\Core\Schema\Exception\InvalidSchemaException;
use App\Core\Schema\ReferentialAction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReferentialActionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ReferentialAction}>
     */
    public static function sqlSpellings(): iterable
    {
        yield 'no action' => ['NO ACTION', ReferentialAction::NoAction];
        yield 'restrict' => ['RESTRICT', ReferentialAction::Restrict];
        yield 'cascade' => ['CASCADE', ReferentialAction::Cascade];
        yield 'set null' => ['SET NULL', ReferentialAction::SetNull];
        yield 'set default' => ['SET DEFAULT', ReferentialAction::SetDefault];
        yield 'lowercase' => ['set null', ReferentialAction::SetNull];
        yield 'surrounding spaces' => [' cascade ', ReferentialAction::Cascade];
    }

    #[DataProvider('sqlSpellings')]
    public function testReadsTheSqlSpelling(string $sql, ReferentialAction $expected): void
    {
        self::assertSame($expected, ReferentialAction::fromSql($sql));
    }

    public function testRefusesAnUnknownAction(): void
    {
        $this->expectException(InvalidSchemaException::class);

        ReferentialAction::fromSql('EXPLODE');
    }

    public function testStoredValuesAreStable(): void
    {
        self::assertSame('no_action', ReferentialAction::NoAction->value);
        self::assertSame('set_null', ReferentialAction::SetNull->value);
        self::assertSame('set_default', ReferentialAction::SetDefault->value);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Core\Schema\Change;

use App\Core\Schema\Change\Exception\InvalidSchemaChangeException;
use App\Core\Schema\Change\NameRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NameRuleTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'LF' => ["x\ny"];
        yield 'CR' => ["x\ry"];
        yield 'NUL' => ["x\0y"];
        yield 'NEL' => ["x\u{85}y"];
        yield 'LS' => ["x\u{2028}y"];
        yield 'PS' => ["x\u{2029}y"];
        yield 'bidi' => ["x\u{202E}y"];
        yield 'invalid UTF-8' => ["x\xC3\x28y"];
        yield 'blank' => ['   '];
        yield 'long' => [str_repeat('a', 129)];
    }

    #[DataProvider('refused')]
    public function testRefusesHostileNames(string $name): void
    {
        $this->expectException(InvalidSchemaChangeException::class);
        NameRule::check($name, 'column');
    }

    public function testKeepsOrdinaryNamesWithQuotesAndSpaces(): void
    {
        foreach (['a b', 'a"b', 'a`b', "o'k", 'x*/y', 'Été', '日本'] as $name) {
            self::assertSame($name, NameRule::check($name, 'column'));
        }
    }
}

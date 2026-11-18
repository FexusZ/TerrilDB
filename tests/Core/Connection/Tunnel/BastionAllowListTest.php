<?php

declare(strict_types=1);

namespace App\Tests\Core\Connection\Tunnel;

use App\Core\Connection\Exception\ForbiddenBastionException;
use App\Core\Connection\SshJump;
use App\Core\Connection\Tunnel\BastionAllowList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BastionAllowListTest extends TestCase
{
    private const KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAAfaux\n-----END OPENSSH PRIVATE KEY-----\n";

    private function jump(string $host, int $port = 22): SshJump
    {
        return new SshJump($host, $port, 'tunnel', self::KEY);
    }

    #[DataProvider('empties')]
    public function testAnEmptyListRefusesEverything(string $configured): void
    {
        $this->expectException(ForbiddenBastionException::class);

        BastionAllowList::fromString($configured)->assertAllowed($this->jump('bastion.example'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function empties(): iterable
    {
        yield 'vide' => [''];
        yield 'espaces' => ['  '];
        yield 'virgules seules' => [' , ,'];
    }

    public function testAHostWithoutPortMeansPort22(): void
    {
        $list = BastionAllowList::fromString('bastion.example');

        $list->assertAllowed($this->jump('bastion.example'));
        $this->expectException(ForbiddenBastionException::class);
        $list->assertAllowed($this->jump('bastion.example', 2222));
    }

    public function testAnExplicitPortIsExact(): void
    {
        $list = BastionAllowList::fromString('a.example:2222, b.example');

        $list->assertAllowed($this->jump('a.example', 2222));
        $list->assertAllowed($this->jump('b.example'));
        $this->expectException(ForbiddenBastionException::class);
        $list->assertAllowed($this->jump('a.example', 22));
    }

    public function testTheComparisonIgnoresCaseAndTheTrailingDot(): void
    {
        BastionAllowList::fromString('Bastion.Example')->assertAllowed($this->jump('bastion.EXAMPLE.'));
        $this->expectNotToPerformAssertions();
    }

    public function testNoWildcardsAndNoSubstrings(): void
    {
        $list = BastionAllowList::fromString('*.example, bastion.example');

        $this->expectException(ForbiddenBastionException::class);
        $list->assertAllowed($this->jump('evil-bastion.example'));
    }

    public function testTheRefusalNeverEchoesTheListOrTheKey(): void
    {
        try {
            BastionAllowList::fromString('secret-host.example')->assertAllowed($this->jump('other.example'));
            self::fail('refused expected');
        } catch (ForbiddenBastionException $refused) {
            self::assertStringNotContainsString('secret-host', $refused->getMessage());
            self::assertStringNotContainsString('AAAAfaux', $refused->getMessage());
        }
    }
}

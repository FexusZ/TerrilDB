<?php

declare(strict_types=1);

namespace App\Tests\Core\Driver;

use App\Core\Driver\ServerFlavour;
use App\Core\Driver\ServerVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServerVersionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ServerFlavour, int, int, int}>
     */
    public static function reported(): iterable
    {
        yield 'MySQL' => ['8.4.5', ServerFlavour::Standard, 8, 4, 5];
        yield 'MySQL, next series' => ['26.1.0', ServerFlavour::Standard, 26, 1, 0];
        yield 'MySQL with a suffix' => ['8.4.5-log', ServerFlavour::Standard, 8, 4, 5];
        yield 'MariaDB' => ['11.8.2-MariaDB-ubu2404', ServerFlavour::MariaDb, 11, 8, 2];
        yield 'MariaDB, replication prefix' => ['5.5.5-10.11.6-MariaDB-log', ServerFlavour::MariaDb, 10, 11, 6];
        yield 'MariaDB 13' => ['13.0.1-MariaDB', ServerFlavour::MariaDb, 13, 0, 1];
        yield 'Vitess' => ['8.0.30-Vitess', ServerFlavour::Vitess, 8, 0, 30];
        yield 'PostgreSQL' => ['18.1 (Debian 18.1-1.pgdg13+1)', ServerFlavour::Standard, 18, 1, 0];
        yield 'PostgreSQL without a minor' => ['18', ServerFlavour::Standard, 18, 0, 0];
        yield 'ClickHouse' => ['26.10.1.123', ServerFlavour::Standard, 26, 10, 1];
        yield 'Redis' => ['7.4.0', ServerFlavour::Standard, 7, 4, 0];
    }

    #[DataProvider('reported')]
    public function testItReadsWhatTheServerReports(string $text, ServerFlavour $flavour, int $major, int $minor, int $patch): void
    {
        $version = ServerVersion::parse($text);

        self::assertNotNull($version);
        self::assertSame($flavour, $version->flavour);
        self::assertSame([$major, $minor, $patch], [$version->major, $version->minor, $version->patch]);
    }

    #[DataProvider('unreadable')]
    public function testSomethingWithoutANumberIsNoVersion(string $text): void
    {
        self::assertNull(ServerVersion::parse($text));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadable(): iterable
    {
        yield 'empty' => [''];
        yield 'words' => ['unknown'];
        yield 'blank' => ['   '];
    }

    public function testItComparesAgainstAMinimum(): void
    {
        $version = ServerVersion::parse('8.0.14');
        \assert(null !== $version);

        self::assertTrue($version->isAtLeast(8, 0, 14));
        self::assertTrue($version->isAtLeast(8));
        self::assertTrue($version->isAtLeast(7, 9, 99));
        self::assertFalse($version->isAtLeast(8, 0, 15));
        self::assertFalse($version->isAtLeast(8, 1));
        self::assertFalse($version->isAtLeast(9));
    }
}

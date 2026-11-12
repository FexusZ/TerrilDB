<?php

declare(strict_types=1);

namespace App\Tests\Core\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\CapabilityMatrix;
use App\Core\Driver\EngineCapability as C;
use App\Core\Driver\ServerVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CapabilityMatrixTest extends TestCase
{
    private static function version(?string $text): ?ServerVersion
    {
        return null === $text ? null : ServerVersion::parse($text);
    }

    public function testMySqlHasTheModernSqlButNeitherVersioningNorSequences(): void
    {
        $capabilities = CapabilityMatrix::for(Engine::MySql, self::version('8.4.5'));

        foreach ([C::RecursiveCte, C::Lateral, C::WindowFunctions, C::JsonTable, C::SkipLocked, C::NoWait, C::InvisibleIndexes, C::ActualPlan, C::GeneratedColumns, C::EnforcedCheck] as $capability) {
            self::assertTrue($capabilities->supports($capability), $capability->value);
        }
        self::assertFalse($capabilities->supports(C::SystemVersioning));
        self::assertFalse($capabilities->supports(C::Sequences));
    }

    public function testMariaDbHasVersioningAndSequences(): void
    {
        $capabilities = CapabilityMatrix::for(Engine::MariaDb, self::version('11.8.2-MariaDB'));

        self::assertTrue($capabilities->supports(C::SystemVersioning));
        self::assertTrue($capabilities->supports(C::Sequences));
        self::assertTrue($capabilities->supports(C::JsonTable));
    }

    public function testAMariaDbServerDeclaredAsMySqlIsRecognisedByItsVersionString(): void
    {
        $capabilities = CapabilityMatrix::for(Engine::MySql, self::version('11.8.2-MariaDB'));

        self::assertTrue($capabilities->supports(C::SystemVersioning));
        self::assertSame(Engine::MariaDb, $capabilities->family);
    }

    public function testAFeatureNeedsItsMinimumVersion(): void
    {
        self::assertFalse(CapabilityMatrix::for(Engine::MySql, self::version('5.7.44'))->supports(C::WindowFunctions));
        self::assertTrue(CapabilityMatrix::for(Engine::MySql, self::version('8.0.2'))->supports(C::WindowFunctions));
        self::assertFalse(CapabilityMatrix::for(Engine::PostgreSql, self::version('16.4'))->supports(C::JsonTable));
        self::assertTrue(CapabilityMatrix::for(Engine::PostgreSql, self::version('17.0'))->supports(C::JsonTable));
        self::assertFalse(CapabilityMatrix::for(Engine::MariaDb, self::version('10.5.9-MariaDB'))->supports(C::JsonTable));
    }

    public function testAnUnknownVersionClaimsNothing(): void
    {
        self::assertSame([], CapabilityMatrix::for(Engine::PostgreSql, null)->supported());
    }

    public function testRedisHasNoneOfThem(): void
    {
        self::assertSame([], CapabilityMatrix::for(Engine::Redis, self::version('7.4.0'))->supported());
    }

    public function testVitessClaimsNothingUntilEachFeatureIsChecked(): void
    {
        $capabilities = CapabilityMatrix::for(Engine::MySql, self::version('8.0.30-Vitess'));

        self::assertSame([], $capabilities->supported());
    }

    public function testPostgreSqlHasNoInvisibleIndexesNorSystemVersioning(): void
    {
        $capabilities = CapabilityMatrix::for(Engine::PostgreSql, self::version('18.1'));

        self::assertTrue($capabilities->supports(C::Lateral));
        self::assertTrue($capabilities->supports(C::Sequences));
        self::assertFalse($capabilities->supports(C::InvisibleIndexes));
        self::assertFalse($capabilities->supports(C::SystemVersioning));
    }

    public function testClickHouseHasNoJsonTableNorLocks(): void
    {
        $capabilities = CapabilityMatrix::for(Engine::ClickHouse, self::version('26.10.1.1'));

        self::assertFalse($capabilities->supports(C::JsonTable));
        self::assertFalse($capabilities->supports(C::SkipLocked));
        self::assertTrue($capabilities->supports(C::WindowFunctions));
    }

    /**
     * @return iterable<string, array{Engine}>
     */
    public static function engines(): iterable
    {
        foreach (Engine::cases() as $engine) {
            yield $engine->value => [$engine];
        }
    }

    #[DataProvider('engines')]
    public function testTheListIsSortedAndMatchesWhatIsSupported(Engine $engine): void
    {
        $capabilities = CapabilityMatrix::for($engine, self::version('99.0.0'));
        $names = array_map(static fn (C $c): string => $c->value, $capabilities->supported());

        foreach (C::cases() as $capability) {
            self::assertSame(\in_array($capability->value, $names, true), $capabilities->supports($capability));
        }
        $sorted = $names;
        sort($sorted);
        self::assertSame($sorted, $names);
    }
}

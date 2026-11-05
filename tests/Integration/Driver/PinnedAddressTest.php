<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\ConnectionParameters;
use App\Core\Driver\DriverRegistry;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Une adresse épinglée est celle que le driver joint, le nom ne sert plus qu'à l'identité (TLS, en-tête Host).
 * Preuve par les deux bouts : un nom qui ne se résout pas se connecte quand même, et un vrai nom ne l'emporte pas sur
 * une autre adresse épinglée.
 */
#[Group('integration')]
final class PinnedAddressTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield from TargetDatabase::all();
        yield 'Redis' => ['TARGET_REDIS_URL'];
    }

    private function drivers(): DriverRegistry
    {
        $drivers = self::getContainer()->get(DriverRegistry::class);
        self::assertInstanceOf(DriverRegistry::class, $drivers);

        return $drivers;
    }

    private function addressOf(ConnectionParameters $real): string
    {
        $address = gethostbyname($real->host);
        if ($address === $real->host && false === filter_var($address, \FILTER_VALIDATE_IP)) {
            self::markTestSkipped('The target host does not resolve.');
        }

        return $address;
    }

    private function renamed(ConnectionParameters $real, string $host): ConnectionParameters
    {
        return new ConnectionParameters($host, $real->port, $real->database, $real->username, $real->password());
    }

    #[DataProvider('targets')]
    public function testANameThatDoesNotResolveStillConnectsThroughThePinnedAddress(string $envVar): void
    {
        $real = TargetDatabase::parameters($envVar);
        $pinned = $this->renamed($real, 'pretend.invalid')->withAddress($this->addressOf($real));

        $target = $this->drivers()->for(TargetDatabase::engine($envVar))->connect(TargetDatabase::engine($envVar), $pinned);

        self::assertSame(TargetDatabase::engine($envVar), $target->engine);
        $target->close();
    }

    #[DataProvider('targets')]
    public function testTheRealNameDoesNotOverrideADifferentPinnedAddress(string $envVar): void
    {
        $real = TargetDatabase::parameters($envVar);
        $this->addressOf($real);

        $this->expectException(TargetConnectionFailedException::class);

        $this->drivers()->for(TargetDatabase::engine($envVar))->connect(TargetDatabase::engine($envVar), $real->withAddress('127.0.0.1'));
    }
}

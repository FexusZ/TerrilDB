<?php

declare(strict_types=1);

namespace App\Tests\Core\Connection;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Exception\InvalidConnectionParametersException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConnectionParametersTest extends TestCase
{
    private function parameters(): ConnectionParameters
    {
        return new ConnectionParameters('db.internal', 5432, 'shop', 'reader', 'p@ss w0rd/é');
    }

    public function testExposesItsParts(): void
    {
        $parameters = $this->parameters();

        self::assertSame('db.internal', $parameters->host);
        self::assertSame(5432, $parameters->port);
        self::assertSame('shop', $parameters->database);
        self::assertSame('reader', $parameters->username);
        self::assertSame('p@ss w0rd/é', $parameters->password());
    }

    public function testTheAddressToConnectToIsTheHostUntilOnePinned(): void
    {
        self::assertSame('db.internal', $this->parameters()->address());
    }

    public function testPinningAnAddressKeepsTheHostForNamesAndLeavesTheStoredFormatAlone(): void
    {
        $pinned = $this->parameters()->withAddress('93.184.216.34');

        self::assertSame('93.184.216.34', $pinned->address());
        self::assertSame('db.internal', $pinned->host);
        self::assertSame($this->parameters()->toJson(), $pinned->toJson());
        self::assertStringNotContainsString('93.184.216.34', $pinned->toJson());
    }

    public function testThePinnedAddressSurvivesAPasswordChange(): void
    {
        self::assertSame('2001:db8::1', $this->parameters()->withAddress('2001:db8::1')->withPassword('other')->address());
    }

    public function testOnlyAnIpAddressCanBePinned(): void
    {
        $this->expectException(InvalidConnectionParametersException::class);

        $this->parameters()->withAddress('not-an-ip.example');
    }

    public function testAnotherDatabaseKeepsTheRestOfTheParameters(): void
    {
        $other = $this->parameters()->withAddress('93.184.216.34')->withDatabase('reporting');

        self::assertSame('reporting', $other->database);
        self::assertSame('db.internal', $other->host);
        self::assertSame(5432, $other->port);
        self::assertSame('reader', $other->username);
        self::assertSame('p@ss w0rd/é', $other->password());
        self::assertSame('93.184.216.34', $other->address());
    }

    public function testTheOtherDatabaseMustBeAValidName(): void
    {
        $this->expectException(InvalidConnectionParametersException::class);

        $this->parameters()->withDatabase("bad\x00name");
    }

    public function testAnEmptyPasswordIsAllowed(): void
    {
        $parameters = new ConnectionParameters('localhost', 3306, 'shop', 'root', '');

        self::assertSame('', $parameters->password());
    }

    public function testJsonRoundTripKeepsEverything(): void
    {
        $copy = ConnectionParameters::fromJson($this->parameters()->toJson());

        self::assertSame('db.internal', $copy->host);
        self::assertSame(5432, $copy->port);
        self::assertSame('shop', $copy->database);
        self::assertSame('reader', $copy->username);
        self::assertSame('p@ss w0rd/é', $copy->password());
    }

    public function testJsonCarriesAFormatVersion(): void
    {
        $decoded = json_decode($this->parameters()->toJson(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertSame(1, $decoded['v']);
    }

    public function testThePasswordNeverShowsInDumpsNorSerialization(): void
    {
        $parameters = $this->parameters();

        self::assertStringNotContainsString('w0rd', print_r($parameters, true));
        ob_start();
        var_dump($parameters);
        self::assertStringNotContainsString('w0rd', (string) ob_get_clean());
        $this->expectException(\LogicException::class);
        serialize($parameters);
    }

    public function testCloneWithAnotherPasswordKeepsTheRest(): void
    {
        $copy = $this->parameters()->withPassword('another one');

        self::assertSame('another one', $copy->password());
        self::assertSame('db.internal', $copy->host);
        self::assertSame('p@ss w0rd/é', $this->parameters()->password());
    }

    /**
     * @return iterable<string, array{string, int, string, string, string}>
     */
    public static function invalidParts(): iterable
    {
        yield 'empty host' => ['', 5432, 'shop', 'reader', 'x'];
        yield 'host with a space' => ['db host', 5432, 'shop', 'reader', 'x'];
        yield 'host with a control character' => ["db\n", 5432, 'shop', 'reader', 'x'];
        yield 'host too long' => [str_repeat('a', 256), 5432, 'shop', 'reader', 'x'];
        yield 'port zero' => ['db', 0, 'shop', 'reader', 'x'];
        yield 'port too high' => ['db', 65536, 'shop', 'reader', 'x'];
        yield 'database too long' => ['db', 5432, str_repeat('a', 129), 'reader', 'x'];
        yield 'empty username' => ['db', 5432, 'shop', '', 'x'];
        yield 'username too long' => ['db', 5432, 'shop', str_repeat('a', 129), 'x'];
        yield 'password that is not UTF-8' => ['db', 5432, 'shop', 'reader', "\xff\xfe"];
        yield 'password too long' => ['db', 5432, 'shop', 'reader', str_repeat('a', 1025)];
    }

    #[DataProvider('invalidParts')]
    public function testInvalidPartsAreRefused(string $host, int $port, string $database, string $username, string $password): void
    {
        $this->expectException(InvalidConnectionParametersException::class);

        new ConnectionParameters($host, $port, $database, $username, $password);
    }

    public function testErrorMessagesNeverEchoThePassword(): void
    {
        try {
            new ConnectionParameters('', 5432, 'shop', 'reader', 'secret-value');
            self::fail('Expected a refusal.');
        } catch (InvalidConnectionParametersException $e) {
            self::assertStringNotContainsString('secret-value', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedJson(): iterable
    {
        yield 'not json' => ['{nope'];
        yield 'not an object' => ['"text"'];
        yield 'unsupported version' => ['{"v":2,"host":"db","port":1,"database":"d","username":"u","password":""}'];
        yield 'missing field' => ['{"v":1,"host":"db","port":1,"database":"d","password":""}'];
        yield 'port as a string' => ['{"v":1,"host":"db","port":"1","database":"d","username":"u","password":""}'];
        yield 'password as a number' => ['{"v":1,"host":"db","port":1,"database":"d","username":"u","password":12}'];
        yield 'invalid values' => ['{"v":1,"host":"","port":1,"database":"d","username":"u","password":""}'];
    }

    #[DataProvider('malformedJson')]
    public function testMalformedJsonIsRefused(string $json): void
    {
        $this->expectException(InvalidConnectionParametersException::class);

        ConnectionParameters::fromJson($json);
    }

    public function testTheDatabaseIsOptional(): void
    {
        $parameters = new ConnectionParameters('db', 5432, '', 'reader', 'x');

        self::assertSame('', $parameters->database);
        self::assertSame('', ConnectionParameters::fromJson($parameters->toJson())->database);
    }

    public function testASchemaIsKeptForTheOpeningOnlyAndNeverEncrypted(): void
    {
        $parameters = (new ConnectionParameters('db', 5432, 'shop', 'reader', 'x'))->withSchema('sales');

        self::assertSame('sales', $parameters->schema);
        self::assertSame('sales', $parameters->withDatabase('other')->schema);
        self::assertSame('sales', $parameters->withAddress('203.0.113.9')->schema);
        self::assertSame('sales', $parameters->withPassword('y')->schema);
        self::assertStringNotContainsString('sales', $parameters->toJson());
        self::assertNull(ConnectionParameters::fromJson($parameters->toJson())->schema);
        self::assertNull($parameters->withSchema(null)->schema);
    }

    public function testAnInvalidSchemaNameIsRefused(): void
    {
        $this->expectException(InvalidConnectionParametersException::class);

        (new ConnectionParameters('db', 5432, 'shop', 'reader', 'x'))->withSchema("bad\x00name");
    }
}

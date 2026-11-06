<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Core\Connection\Engine;
use App\Crypto\KeyDerivation;
use App\Entity\DatabaseConnection;
use App\Entity\Exception\InvalidDatabaseConnectionException;
use App\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class DatabaseConnectionTest extends TestCase
{
    private const HASH = '$argon2id$v=19$m=16384,t=3,p=1$c29tZXNhbHQ$aGFzaGhhc2hoYXNoaGFzaA';
    private const BLOB = 'v1:AAAAencrypted';

    private function user(string $name = 'alice'): User
    {
        return new User(
            $name.'@example.test',
            $name,
            self::HASH,
            (new KeyDerivation(opsLimit: 1, memLimit: 8192))->newParameters(),
            new \DateTimeImmutable('2026-10-29T12:00:00Z'),
        );
    }

    private function connection(?User $owner = null): DatabaseConnection
    {
        return new DatabaseConnection(
            Uuid::v7(),
            $owner ?? $this->user(),
            'Production',
            Engine::PostgreSql,
            self::BLOB,
            new \DateTimeImmutable('2026-10-31T10:00:00Z'),
        );
    }

    public function testExposesWhatWasGiven(): void
    {
        $owner = $this->user();
        $id = Uuid::v7();

        $connection = new DatabaseConnection($id, $owner, 'Production', Engine::PostgreSql, self::BLOB, new \DateTimeImmutable('2026-10-31T10:00:00Z'));

        self::assertTrue($id->equals($connection->getId()));
        self::assertSame($owner, $connection->getOwner());
        self::assertSame('Production', $connection->getName());
        self::assertSame(Engine::PostgreSql, $connection->getEngine());
        self::assertSame(self::BLOB, $connection->getEncryptedParameters());
        self::assertEquals(new \DateTimeImmutable('2026-10-31T10:00:00Z'), $connection->getCreatedAt());
        self::assertEquals($connection->getCreatedAt(), $connection->getUpdatedAt());
    }

    public function testDatesAreKeptInUtc(): void
    {
        $connection = new DatabaseConnection(
            Uuid::v7(),
            $this->user(),
            'Production',
            Engine::MySql,
            self::BLOB,
            new \DateTimeImmutable('2026-10-31T12:00:00+02:00'),
        );

        self::assertSame('2026-10-31T10:00:00+00:00', $connection->getCreatedAt()->format(\DATE_ATOM));
        self::assertSame('2026-10-31T10:00:00+00:00', $connection->getUpdatedAt()->format(\DATE_ATOM));
    }

    public function testTheNameIsTrimmed(): void
    {
        $connection = new DatabaseConnection(Uuid::v7(), $this->user(), "  Staging \n", Engine::MySql, self::BLOB, new \DateTimeImmutable());

        self::assertSame('Staging', $connection->getName());
    }

    public function testBelongsToItsOwnerOnly(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $connection = $this->connection($alice);

        self::assertTrue($connection->belongsTo($alice));
        self::assertFalse($connection->belongsTo($bob));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ["  \t "];
        yield 'too long' => [str_repeat('é', 101)];
        yield 'control character' => ["Prod\x00uction"];
        yield 'new line' => ["Prod\nuction"];
    }

    #[DataProvider('invalidNames')]
    public function testInvalidNamesAreRefused(string $name): void
    {
        $this->expectException(InvalidDatabaseConnectionException::class);

        new DatabaseConnection(Uuid::v7(), $this->user(), $name, Engine::MySql, self::BLOB, new \DateTimeImmutable());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notEncrypted(): iterable
    {
        yield 'empty' => [''];
        yield 'clear JSON' => ['{"v":1,"host":"db","port":1,"database":"d","username":"u","password":"p"}'];
        yield 'no version prefix' => ['AAAAencrypted'];
    }

    #[DataProvider('notEncrypted')]
    public function testOnlyEncryptedParametersAreAccepted(string $blob): void
    {
        $this->expectException(InvalidDatabaseConnectionException::class);

        new DatabaseConnection(Uuid::v7(), $this->user(), 'Production', Engine::MySql, $blob, new \DateTimeImmutable());
    }

    public function testUpdateChangesTheEditableFieldsAndTheUpdateDate(): void
    {
        $connection = $this->connection();

        $connection->update('Staging', Engine::MariaDb, 'v1:other', new \DateTimeImmutable('2026-11-02T08:30:00Z'));

        self::assertSame('Staging', $connection->getName());
        self::assertSame(Engine::MariaDb, $connection->getEngine());
        self::assertSame('v1:other', $connection->getEncryptedParameters());
        self::assertSame('2026-11-02T08:30:00+00:00', $connection->getUpdatedAt()->format(\DATE_ATOM));
        self::assertSame('2026-10-31T10:00:00+00:00', $connection->getCreatedAt()->format(\DATE_ATOM));
    }

    public function testUpdateValidatesLikeTheConstructor(): void
    {
        $connection = $this->connection();

        try {
            $connection->update('', Engine::MariaDb, 'v1:other', new \DateTimeImmutable());
            self::fail('Expected a refusal.');
        } catch (InvalidDatabaseConnectionException) {
            self::assertSame('Production', $connection->getName());
            self::assertSame(Engine::PostgreSql, $connection->getEngine());
        }
    }

    public function testRotationReplacesOnlyTheEncryptedParameters(): void
    {
        $connection = $this->connection();

        $connection->rotateEncryptedParameters('v1:rotated');

        self::assertSame('v1:rotated', $connection->getEncryptedParameters());
        self::assertSame('Production', $connection->getName());
        self::assertEquals($connection->getCreatedAt(), $connection->getUpdatedAt(), 'une rotation de clé n\'est pas une modification');
    }

    public function testRotationRefusesClearData(): void
    {
        $this->expectException(InvalidDatabaseConnectionException::class);

        $this->connection()->rotateEncryptedParameters('{"password":"p"}');
    }

    public function testStartsWithoutColorAtTheTopOfTheList(): void
    {
        $connection = $this->connection();

        self::assertNull($connection->getColor());
        self::assertSame(0, $connection->getPosition());
    }

    public function testTheColorAndThePositionCanChange(): void
    {
        $connection = $this->connection();

        $connection->setColor(\App\Entity\ConnectionColor::Teal);
        $connection->moveTo(4);
        self::assertSame(\App\Entity\ConnectionColor::Teal, $connection->getColor());
        self::assertSame(4, $connection->getPosition());

        $connection->setColor(null);
        self::assertNull($connection->getColor());
    }

    public function testAPositionCannotBeNegative(): void
    {
        $this->expectException(InvalidDatabaseConnectionException::class);

        $this->connection()->moveTo(-1);
    }
}

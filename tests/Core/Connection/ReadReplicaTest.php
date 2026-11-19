<?php

declare(strict_types=1);

namespace App\Tests\Core\Connection;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Driver\DbalConnector;
use PHPUnit\Framework\TestCase;

/**
 * Lecture sur les tablets réplica d'un Vitess (#40) : une option de connexion, éteinte par défaut, qui cible `@replica`.
 */
final class ReadReplicaTest extends TestCase
{
    private function parameters(string $database = 'shop', bool $replica = false): ConnectionParameters
    {
        return new ConnectionParameters('db.internal', 3306, $database, 'reader', 'secret', readReplica: $replica);
    }

    public function testItIsOffByDefaultAndTheStoredFormatIsUnchanged(): void
    {
        $json = $this->parameters()->toJson();

        self::assertFalse($this->parameters()->readReplica);
        self::assertStringNotContainsString('replica', $json);
        self::assertFalse(ConnectionParameters::fromJson($json)->readReplica);
    }

    public function testItSurvivesTheEncryptedJson(): void
    {
        $back = ConnectionParameters::fromJson($this->parameters('shop', true)->toJson());

        self::assertTrue($back->readReplica);
        self::assertSame('shop', $back->database, 'la base reste son nom : la cible @replica n\'est ajoutée qu\'à l\'ouverture');
    }

    public function testItFollowsTheOtherChanges(): void
    {
        $parameters = $this->parameters('shop', true)->withAddress('93.184.216.34')->withDatabase('other')->withSchema('s')->withPassword('new')->withSocket('/run/tunnels/x/tunnel.sock');

        self::assertTrue($parameters->readReplica);
    }

    public function testTheDatabaseIsOpenedOnTheReplicaTablets(): void
    {
        $options = (new DbalConnector())->options(Engine::MySql, $this->parameters('shop', true));

        self::assertSame('shop@replica', $options['dbname'] ?? null);
    }

    public function testWithoutADatabaseOnlyTheTargetIsGiven(): void
    {
        $options = (new DbalConnector())->options(Engine::MySql, $this->parameters('', true));

        self::assertSame('@replica', $options['dbname'] ?? null);
    }

    public function testATargetAlreadyInTheNameIsNotDoubled(): void
    {
        $options = (new DbalConnector())->options(Engine::MySql, $this->parameters('shop@replica', true));

        self::assertSame('shop@replica', $options['dbname'] ?? null);
    }

    public function testWithoutTheOptionTheDatabaseIsUntouched(): void
    {
        $options = (new DbalConnector())->options(Engine::MySql, $this->parameters('shop'));

        self::assertSame('shop', $options['dbname'] ?? null);
    }
}

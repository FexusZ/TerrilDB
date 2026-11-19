<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\TargetConnection;
use App\Core\Guard\StatementKind;
use App\Core\Query\Exception\QueryFailedException;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryOptions;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\SkippedWithMessageException;
use PHPUnit\Framework\TestCase;

/**
 * L'option « lire sur le réplica » (#40) sur un vrai vtgate : la cible `@replica` est ajoutée au nom de la base à l'ouverture,
 * l'exploration et les lectures marchent comme sur le primaire. Sauté si la cible `target-vitess` est éteinte (profil « vitess »,
 * voir docs/vitess-tests.md). `vttestserver` n'a qu'un `mysqld` : la réplication elle-même n'est pas éprouvée ici.
 */
#[Group('integration')]
final class VitessReplicaTest extends TestCase
{
    private ?TargetConnection $target = null;

    protected function tearDown(): void
    {
        $this->target?->close();
    }

    private function open(bool $replica): TargetConnection
    {
        try {
            $parameters = TargetDatabase::parameters('TARGET_VITESS_URL');
            $parameters = new ConnectionParameters($parameters->host, $parameters->port, $parameters->database, $parameters->username, $parameters->password(), readReplica: $replica);
            $this->target = (new MySqlDriver(new DbalConnector()))->connect(Engine::MySql, $parameters);
        } catch (SkippedWithMessageException $e) {
            throw $e;
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped('TARGET_VITESS_URL is unreachable (docker compose --profile vitess up -d --wait target-vitess).');
        }

        return $this->target;
    }

    private function scalar(TargetConnection $target, string $sql): mixed
    {
        $result = $target->runner()->run($sql, StatementKind::Read, new QueryOptions(QueryMode::ReadOnly, 10000, 1000));

        return $result->rows[0][0] ?? null;
    }

    public function testTheTablesOfTheKeyspaceAreExploredThroughTheReplica(): void
    {
        $target = $this->open(true);

        $names = array_map(static fn ($t): string => $t->name, $target->introspector()->listTables());

        self::assertContains('order_items', $names);
        self::assertEquals(7000, $this->scalar($target, 'SELECT COUNT(*) FROM order_items'));
    }

    public function testADataWriteIsRefusedOnTheReplica(): void
    {
        $target = $this->open(true);

        $this->expectException(QueryFailedException::class);
        $target->runner()->run("INSERT INTO categories (name) VALUES ('zz_replica_probe')", StatementKind::Write, new QueryOptions(QueryMode::ReadWrite, 10000, 1000));
    }
}

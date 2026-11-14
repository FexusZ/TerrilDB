<?php

declare(strict_types=1);

namespace App\Tests\Driver\MySql;

use App\Core\Connection\Engine;
use App\Core\Guard\StatementKind;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryOptions;
use App\Driver\MySql\MySqlQueryRunner;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Sur Vitess, vtgate accepte `SET SESSION TRANSACTION READ ONLY` sans le transmettre : la lecture seule passe par
 * `START TRANSACTION READ ONLY` (#39). Le runner est essayé sans cluster, avec une connexion qui enregistre ce qui part.
 */
final class MySqlQueryRunnerVitessTest extends TestCase
{
    public function testReadOnlyOpensAReadOnlyTransactionOnVitess(): void
    {
        $sent = $this->sentBy('8.0.35-Vitess', QueryMode::ReadOnly);

        self::assertContains('START TRANSACTION READ ONLY', $sent);
        self::assertNotContains('START TRANSACTION', $sent);
    }

    public function testReadWriteStaysAPlainTransactionOnVitess(): void
    {
        $sent = $this->sentBy('8.0.35-Vitess', QueryMode::ReadWrite);

        self::assertContains('START TRANSACTION', $sent);
        self::assertNotContains('START TRANSACTION READ ONLY', $sent);
    }

    public function testPlainMysqlKeepsTheSessionSetting(): void
    {
        $sent = $this->sentBy('8.4.6', QueryMode::ReadOnly);

        self::assertContains('SET SESSION TRANSACTION READ ONLY', $sent);
        self::assertContains('START TRANSACTION', $sent);
        self::assertNotContains('START TRANSACTION READ ONLY', $sent);
    }

    /**
     * @return list<string>
     */
    private function sentBy(string $version, QueryMode $mode): array
    {
        $pdo = new class extends \PDO {
            /** @var list<string> */
            public array $sent = [];

            public function __construct()
            {
            }

            public function exec(string $statement): int
            {
                $this->sent[] = $statement;

                return 0;
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement
            {
                $this->sent[] = $query;

                return new class extends \PDOStatement {
                    public function columnCount(): int
                    {
                        return 0;
                    }

                    public function rowCount(): int
                    {
                        return 0;
                    }
                };
            }
        };

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn($version);
        $connection->method('getNativeConnection')->willReturn($pdo);

        MySqlQueryRunner::forConnection($connection, Engine::MySql)
            ->run('UPDATE t SET a = 1', StatementKind::Write, new QueryOptions($mode, 1000, 10));

        return $pdo->sent;
    }
}

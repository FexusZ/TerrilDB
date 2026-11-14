<?php

declare(strict_types=1);

namespace App\Tests\Driver\MySql;

use App\Core\Connection\Engine;
use App\Core\Guard\StatementKind;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryOptions;
use App\Driver\MySql\MySqlQueryRunner;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sur Vitess, vtgate accepte `SET SESSION TRANSACTION READ ONLY` sans le transmettre : la lecture seule passe par
 * `START TRANSACTION READ ONLY` (#39). Le runner est essayé sans cluster, avec une connexion qui enregistre ce qui part.
 */
final class MySqlQueryRunnerVitessTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function versions(): iterable
    {
        yield 'vitess' => ['8.0.35-Vitess'];
        yield 'vitess en minuscules' => ['8.0.35-vitess'];
        yield 'version renommée par vtgate' => ['9.9.9'];
        yield 'version illisible' => [''];
        yield 'mysql ordinaire' => ['8.4.6'];
    }

    #[DataProvider('versions')]
    public function testReadOnlyAlwaysOpensAReadOnlyTransaction(string $version): void
    {
        $sent = $this->sentBy($version, QueryMode::ReadOnly);

        self::assertContains('START TRANSACTION READ ONLY', $sent);
        self::assertContains('SET SESSION TRANSACTION READ ONLY', $sent);
        self::assertNotContains('START TRANSACTION', $sent);
    }

    public function testReadOnlyDoesNotDependOnReadingTheVersion(): void
    {
        $sent = $this->sentBy(new \RuntimeException('connexion perdue'), QueryMode::ReadOnly);

        self::assertContains('START TRANSACTION READ ONLY', $sent);
    }

    public function testReadWriteStaysAPlainTransaction(): void
    {
        $sent = $this->sentBy('8.0.35-Vitess', QueryMode::ReadWrite);

        self::assertContains('START TRANSACTION', $sent);
        self::assertNotContains('START TRANSACTION READ ONLY', $sent);
    }

    /**
     * @return list<string>
     */
    private function sentBy(string|\Throwable $version, QueryMode $mode): array
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
        $connection->method('fetchOne')->willReturnCallback(static fn () => $version instanceof \Throwable ? throw $version : $version);
        $connection->method('getNativeConnection')->willReturn($pdo);

        new MySqlQueryRunner($connection, Engine::MySql)
            ->run('UPDATE t SET a = 1', StatementKind::Write, new QueryOptions($mode, 1000, 10));

        return $pdo->sent;
    }
}

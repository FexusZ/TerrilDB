<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\TargetConnection;
use App\Core\Guard\StatementGuard;
use App\Core\Guard\StatementKind;
use App\Core\Query\Exception\QueryFailedException;
use App\Core\Query\Exception\QueryRefusedException;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryOptions;
use App\Core\Query\QueryService;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\SkippedWithMessageException;
use PHPUnit\Framework\TestCase;

/**
 * La lecture seule sur le vrai cluster Vitess (#39). Mesuré : vtgate accepte `SET SESSION TRANSACTION READ ONLY` mais ne le
 * transmet pas (`@@session.transaction_read_only` reste 0), si bien que sur le PRIMAIRE le moteur n'imposait rien ; seul
 * `START TRANSACTION READ ONLY` est appliqué par le tablet, et seulement aux DML (un DDL s'y exécute quand même). Un réplica
 * refuse tout (vtgate : « supported only for primary tablet type », mysqld : `super_read_only`). Sauté si le cluster est éteint
 * (profil « vitess-cluster », voir docs/vitess-tests.md).
 */
#[Group('integration')]
final class VitessReadOnlyTest extends TestCase
{
    private const ENV = 'TARGET_VITESS_CLUSTER_URL';
    private const TABLE = 'zz_readonly_probe';

    /** @var list<TargetConnection> */
    private array $opened = [];

    /**
     * @return iterable<string, array{?string}>
     */
    public static function tablets(): iterable
    {
        yield 'primaire (sans cible)' => [null];
        yield 'primaire (@primary)' => ['shop@primary'];
        yield 'réplica' => ['shop@replica'];
    }

    protected function setUp(): void
    {
        $writer = $this->open(null);
        $writer->runner()->run('DROP TABLE IF EXISTS '.self::TABLE, StatementKind::Schema, $this->options(QueryMode::ReadWrite));
        $writer->runner()->run('CREATE TABLE '.self::TABLE.' (id INT PRIMARY KEY, v VARCHAR(20))', StatementKind::Schema, $this->options(QueryMode::ReadWrite));
        $writer->runner()->run('INSERT INTO '.self::TABLE." VALUES (1, 'orig')", StatementKind::Write, $this->options(QueryMode::ReadWrite));
    }

    protected function tearDown(): void
    {
        if ([] !== $this->opened) {
            $this->opened[0]->runner()->run('DROP TABLE IF EXISTS '.self::TABLE, StatementKind::Schema, $this->options(QueryMode::ReadWrite));
        }
        foreach ($this->opened as $target) {
            $target->close();
        }
    }

    #[DataProvider('tablets')]
    public function testADataWriteInReadOnlyModeIsRefusedByTheEngine(?string $keyspace): void
    {
        $target = $this->open($keyspace);

        foreach ([
            "INSERT INTO %s VALUES (2, 'x')",
            "UPDATE %s SET v = 'hacked' WHERE id = 1",
            'DELETE FROM %s WHERE id = 1',
            "REPLACE INTO %s VALUES (1, 'r')",
        ] as $template) {
            try {
                $target->runner()->run(\sprintf($template, self::TABLE), StatementKind::Write, $this->options(QueryMode::ReadOnly));
                self::fail(\sprintf('"%s" should have been refused in read-only mode.', $template));
            } catch (QueryFailedException) {
                // refusé par le moteur : attendu
            }
        }

        self::assertEquals([[1, 'orig']], $this->rows($this->open(null), 'SELECT id, v FROM '.self::TABLE));
    }

    #[DataProvider('tablets')]
    public function testAReadStillWorksInReadOnlyMode(?string $keyspace): void
    {
        $result = $this->open($keyspace)->runner()->run('SELECT COUNT(*) AS n FROM order_items', StatementKind::Read, $this->options(QueryMode::ReadOnly));

        self::assertEquals([[7000]], $result->rows);
    }

    #[DataProvider('tablets')]
    public function testTheGuardRefusesStructureAndDataWritesInReadOnlyMode(?string $keyspace): void
    {
        $queries = new QueryService(new StatementGuard());
        $target = $this->open($keyspace);

        foreach (['CREATE TABLE zz_never (id INT)', 'ALTER TABLE '.self::TABLE.' ADD COLUMN q INT', 'TRUNCATE TABLE '.self::TABLE, 'DROP TABLE '.self::TABLE, 'DELETE FROM '.self::TABLE] as $sql) {
            try {
                $queries->execute($target, $sql, $this->options(QueryMode::ReadOnly), true);
                self::fail(\sprintf('"%s" should have been refused.', $sql));
            } catch (QueryRefusedException) {
                // le garde est la seule barrière du DDL sur le primaire
            }
        }

        self::assertEquals([[1, 'orig']], $this->rows($this->open(null), 'SELECT id, v FROM '.self::TABLE));
    }

    public function testTheEngineAloneDoesNotStopDdlOnThePrimary(): void
    {
        // Limite connue, verrouillée : sur le primaire, `START TRANSACTION READ ONLY` laisse passer un DDL (vtgate l'exécute hors
        // de la transaction). Si ce test casse, la barrière du moteur a progressé : mettre à jour docs/architecture/garde-et-scripts.md.
        $target = $this->open(null);

        try {
            $target->runner()->run('CREATE TABLE zz_readonly_ddl (id INT)', StatementKind::Schema, $this->options(QueryMode::ReadOnly));
            $created = true;
        } catch (QueryFailedException) {
            $created = false;
        } finally {
            $this->open(null)->runner()->run('DROP TABLE IF EXISTS zz_readonly_ddl', StatementKind::Schema, $this->options(QueryMode::ReadWrite));
        }

        self::assertTrue($created, 'The primary now refuses DDL in read-only mode: update the documentation.');
    }

    private function options(QueryMode $mode): QueryOptions
    {
        return new QueryOptions($mode, 30_000, 1000);
    }

    private function open(?string $keyspace): TargetConnection
    {
        $url = getenv(self::ENV);
        if (false === $url || '' === $url) {
            throw new SkippedWithMessageException(self::ENV.' is not set.');
        }
        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['host'], $parts['port'], $parts['user'], $parts['path'])) {
            throw new SkippedWithMessageException(self::ENV.' is malformed.');
        }
        $parameters = new ConnectionParameters($parts['host'], $parts['port'], $keyspace ?? ltrim($parts['path'], '/'), $parts['user'], $parts['pass'] ?? '');

        try {
            $target = (new MySqlDriver(new DbalConnector()))->connect(Engine::MySql, $parameters);
        } catch (TargetConnectionFailedException) {
            throw new SkippedWithMessageException('The Vitess cluster is unreachable (docker compose --profile vitess-cluster up -d vitess-init vitess-vtgate).');
        }

        return $this->opened[] = $target;
    }

    /**
     * @return list<list<bool|float|int|string|null>>
     */
    private function rows(TargetConnection $target, string $sql): array
    {
        $result = $target->runner()->run($sql, StatementKind::Read, $this->options(QueryMode::ReadOnly));

        return $result->rows;
    }
}

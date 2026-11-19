<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\TargetConnection;
use App\Core\Guard\Decision;
use App\Core\Guard\StatementGuard;
use App\Core\Guard\StatementKind;
use App\Core\Query\Exception\QueryFailedException;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryOptions;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\SkippedWithMessageException;
use PHPUnit\Framework\TestCase;

/**
 * Les verbes de lecture propres à vtgate (#40) passent le garde ET s'exécutent en lecture seule sur un vrai vtgate ; `VEXPLAIN` se juge comme `EXPLAIN ANALYZE`
 * (`TRACE` joue la requête expliquée, même une écriture). Sauté si `target-vitess` est éteinte.
 */
#[Group('integration')]
final class VitessShowTest extends TestCase
{
    private ?TargetConnection $target = null;

    protected function tearDown(): void
    {
        $this->target?->close();
    }

    private function open(): TargetConnection
    {
        try {
            $this->target = (new MySqlDriver(new DbalConnector()))->connect(Engine::MySql, TargetDatabase::parameters('TARGET_VITESS_URL'));
        } catch (SkippedWithMessageException $e) {
            throw $e;
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped('TARGET_VITESS_URL is unreachable (docker compose --profile vitess up -d --wait target-vitess).');
        }

        return $this->target;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reads(): iterable
    {
        foreach (['SHOW VITESS_TABLETS', 'SHOW VITESS_SHARDS', 'SHOW VITESS_KEYSPACES', 'SHOW KEYSPACES', 'SHOW VSCHEMA TABLES', 'SHOW VITESS_MIGRATIONS'] as $sql) {
            yield $sql => [$sql];
        }
    }

    #[DataProvider('reads')]
    public function testAVitessShowPassesTheGuardAndRunsReadOnly(string $sql): void
    {
        $target = $this->open();
        $verdict = (new StatementGuard())->check($sql, Engine::MySql, QueryMode::ReadOnly);
        self::assertSame(Decision::Allowed, $verdict->decision);
        self::assertNotNull($verdict->kind);

        $result = $target->runner()->run($sql, $verdict->kind, new QueryOptions(QueryMode::ReadOnly, 10000, 1000));

        self::assertNotSame([], $result->columns, 'le moteur répond par un jeu de colonnes');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function vexplains(): iterable
    {
        foreach (['PLAN', 'KEYS', 'QUERIES', 'ALL', 'TRACE'] as $form) {
            yield $form => ['VEXPLAIN '.$form.' SELECT * FROM order_items WHERE id = 1'];
        }
    }

    #[DataProvider('vexplains')]
    public function testAVExplainOfAReadPassesTheGuardAndRunsReadOnly(string $sql): void
    {
        $target = $this->open();
        $verdict = (new StatementGuard())->check($sql, Engine::MySql, QueryMode::ReadOnly);
        self::assertSame(Decision::Allowed, $verdict->decision);
        self::assertNotNull($verdict->kind);

        $result = $target->runner()->run($sql, $verdict->kind, new QueryOptions(QueryMode::ReadOnly, 10000, 1000));

        self::assertNotSame([], $result->columns);
    }

    public function testAVExplainThatRunsAWriteIsStoppedByTheGuardInReadOnlyAndAskedInReadWrite(): void
    {
        foreach (['QUERIES', 'ALL', 'TRACE'] as $form) {
            $sql = 'VEXPLAIN '.$form.' DELETE FROM order_items';
            self::assertSame(Decision::Refused, (new StatementGuard())->check($sql, Engine::MySql, QueryMode::ReadOnly)->decision, $sql);
            self::assertSame(Decision::NeedsConfirmation, (new StatementGuard())->check($sql, Engine::MySql, QueryMode::ReadWrite)->decision, $sql);
        }
    }

    public function testTheEngineItselfRefusesAWriteRunByVExplainTraceInAReadOnlyTransaction(): void
    {
        // Le filet du moteur, si le filtre se trompait : le tablet refuse l'écriture jouée par TRACE dans la transaction en lecture seule.
        $target = $this->open();

        $this->expectException(QueryFailedException::class);
        $target->runner()->run("VEXPLAIN TRACE UPDATE categories SET name = 'zz_probe' WHERE id = -1", StatementKind::Inspect, new QueryOptions(QueryMode::ReadOnly, 10000, 1000));
    }
}

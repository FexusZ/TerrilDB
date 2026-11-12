<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Core\Diagnostics\Availability;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\LockConflict;
use App\Core\Driver\TargetConnection;
use App\Core\Schema\Change\ColumnDraft;
use App\Core\Schema\Change\Exception\SchemaChangeFailedException;
use App\Core\Schema\Change\ForeignKeyDraft;
use App\Core\Schema\Change\IndexDraft;
use App\Core\Schema\Change\SchemaChangePlanner;
use App\Core\Schema\Change\SchemaChangeService;
use App\Core\Schema\Change\TableDraft;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use App\Driver\PostgreSql\PostgreSqlDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Le pré-contrôle des verrous dans l'aperçu et l'explication d'un délai dépassé, sur les vrais moteurs : une vraie transaction
 * reste ouverte sur une table jetable pendant que le service de structure travaille.
 */
#[Group('integration')]
final class SchemaChangeLockCheckTest extends TestCase
{
    private ?LockScenario $scenario = null;

    protected function tearDown(): void
    {
        $this->scenario?->cleanup();
        $this->scenario = null;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield from TargetDatabase::relational();
    }

    private function open(string $envVar): TargetConnection
    {
        $engine = TargetDatabase::engine($envVar);
        $driver = Engine::PostgreSql === $engine ? new PostgreSqlDriver(new DbalConnector()) : new MySqlDriver(new DbalConnector());
        try {
            return $driver->connect($engine, TargetDatabase::parameters($envVar));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped(\sprintf('%s is unreachable (docker compose --profile targets up -d --wait).', $envVar));
        }
    }

    private function begin(string $envVar): LockScenario
    {
        $this->open($envVar)->close();

        return $this->scenario = LockScenario::begin($envVar, 'read');
    }

    private function addColumn(TargetConnection $target, string $table): TableDraft
    {
        $before = $target->introspector()->describeTable($table);

        return new TableDraft(
            $table,
            [
                ...array_map(static fn ($c): ColumnDraft => new ColumnDraft($c->name, $c->name, $c->type, $c->nullable, $c->default, $c->autoIncrement), $before->columns),
                new ColumnDraft(null, 'extra', Engine::PostgreSql === $target->engine ? 'integer' : 'int', true, null, false),
            ],
            array_map(static fn ($i): IndexDraft => new IndexDraft($i->name, $i->name, $i->columns, $i->unique, $i->primary, null, null), $before->indexes),
            array_map(static fn ($f): ForeignKeyDraft => new ForeignKeyDraft($f->name, $f->name, $f->columns, $f->referencedTable, $f->referencedColumns, $f->onUpdate, $f->onDelete), $before->foreignKeys),
        );
    }

    #[DataProvider('targets')]
    public function testThePreviewNamesTheOpenTransactionThatHoldsTheTable(string $envVar): void
    {
        $scenario = $this->begin($envVar);
        $target = $this->open($envVar);

        $preview = (new SchemaChangeService())->preview($target, $scenario->table, $this->addColumn($target, $scenario->table), null);
        $target->close();

        self::assertNotSame([], $preview->plan->statements, 'The preview is still served.');
        self::assertNotNull($preview->holders);
        if (Engine::MariaDb === TargetDatabase::engine($envVar)) {
            self::assertSame(Availability::Disabled, $preview->holders->status->availability);

            return;
        }
        self::assertSame(Availability::Available, $preview->holders->status->availability);
        self::assertContains($scenario->holderId(), array_map(static fn ($h): string => $h->session, $preview->holders->holders));
    }

    #[DataProvider('targets')]
    public function testThePreviewOfAnUntouchedTableFindsNoHolder(string $envVar): void
    {
        $scenario = $this->begin($envVar);
        $scenario->release();
        $target = $this->open($envVar);

        $preview = (new SchemaChangeService())->preview($target, $scenario->table, $this->addColumn($target, $scenario->table), null);
        $target->close();

        self::assertNotNull($preview->holders);
        self::assertSame([], $preview->holders->holders);
    }

    #[DataProvider('targets')]
    public function testAnAlterThatTimesOutSaysWhoHoldsTheTable(string $envVar): void
    {
        $scenario = $this->begin($envVar);
        $target = $this->open($envVar);
        $service = new SchemaChangeService();
        $draft = $this->addColumn($target, $scenario->table);
        $fingerprint = SchemaChangePlanner::fingerprint($target->introspector()->describeTable($scenario->table));

        $started = microtime(true);
        try {
            $service->apply($target, $scenario->table, $draft, $fingerprint, false);
            self::fail('The ALTER should wait for the open transaction, then time out.');
        } catch (SchemaChangeFailedException $e) {
            self::assertLessThan(15, microtime(true) - $started);
            self::assertSame([], $e->applied);
            self::assertSame(LockConflict::LockTimeout, $e->lock);
            self::assertNotNull($e->holders);
            if (Engine::MariaDb !== TargetDatabase::engine($envVar)) {
                self::assertContains($scenario->holderId(), array_map(static fn ($h): string => $h->session, $e->holders->holders));
            }
        } finally {
            $target->close();
        }
    }

    #[DataProvider('targets')]
    public function testAFailureThatIsNotATimeoutHasNoHolderReport(string $envVar): void
    {
        $scenario = $this->begin($envVar);
        $scenario->release();
        $target = $this->open($envVar);
        $before = $target->introspector()->describeTable($scenario->table);
        $draft = new TableDraft($scenario->table, [...$this->addColumn($target, $scenario->table)->columns, new ColumnDraft(null, 'bad', 'nonexistent_type_xyz', true, null, false)], [], []);

        try {
            (new SchemaChangeService())->apply($target, $scenario->table, $draft, SchemaChangePlanner::fingerprint($before), true);
            self::fail('The engine should refuse an unknown type.');
        } catch (SchemaChangeFailedException $e) {
            self::assertNull($e->lock);
            self::assertNull($e->holders);
        } finally {
            $target->close();
        }
    }
}

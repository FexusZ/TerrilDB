<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\TargetConnection;
use App\Core\Guard\StatementKind;
use App\Core\Query\QueryOptions;
use App\Core\Schema\Change\ColumnDraft;
use App\Core\Schema\Change\DdlStatement;
use App\Core\Schema\Change\ForeignKeyDraft;
use App\Core\Schema\Change\IndexDraft;
use App\Core\Schema\Change\TableDraft;
use App\Core\Schema\TableDefinition;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;

/**
 * Socle des tests qui comparent MySQL et MariaDB : une cible ouverte par le vrai driver, des objets jetables retirés
 * en tearDown (jamais le schéma partagé), une seconde connexion pour modifier la structure (le runner laisse sa session en
 * lecture seule).
 */
trait MySqlFamilyTarget
{
    private ?TargetConnection $target = null;
    /** Seconde connexion pour LIRE par le runner : il laisse sa session en lecture seule, ce qui bloquerait le DDL de la première. */
    private ?TargetConnection $reader = null;
    private Engine $engine;
    private string $env;

    /** @var list<string> */
    private array $drops = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->drops) as $drop) {
            try {
                $this->raw($drop);
            } catch (\Throwable) {
            }
        }
        $this->target?->close();
        $this->reader?->close();
        $this->target = $this->reader = null;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL'];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL'];
        yield 'MariaDB' => ['TARGET_MARIADB_URL'];
        yield 'MariaDB 13' => ['TARGET_MARIADB13_URL'];
    }

    private function open(string $env): TargetConnection
    {
        $this->env = $env;
        $this->engine = TargetDatabase::engine($env);
        try {
            return $this->target = (new MySqlDriver(new DbalConnector()))->connect($this->engine, TargetDatabase::parameters($env));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped('The target is unreachable (docker compose --profile targets up -d --wait).');
        }
    }

    /**
     * @return list<list<bool|float|int|string|null>>
     */
    private function query(string $sql, ?QueryOptions $options = null): array
    {
        $this->reader ??= (new MySqlDriver(new DbalConnector()))->connect($this->engine, TargetDatabase::parameters($this->env));

        return $this->reader->runner()->run($sql, StatementKind::Read, $options ?? new QueryOptions())->rows;
    }

    private function raw(string $sql): void
    {
        \assert(null !== $this->target);
        $this->target->ddl()->execute([new DdlStatement($sql, 'raw', 'raw', false)], false);
    }

    private function table(string $columns, string $options = 'ENGINE=InnoDB'): string
    {
        $name = 'tdb_diff_'.bin2hex(random_bytes(4));
        $this->drops[] = 'DROP TABLE IF EXISTS `'.$name.'`';
        $this->raw('CREATE TABLE `'.$name.'` ('.$columns.') '.$options);

        return $name;
    }

    private function describe(string $name): TableDefinition
    {
        \assert(null !== $this->target);

        return $this->target->introspector()->describeTable($name);
    }

    private function mariaDb(): bool
    {
        return Engine::MariaDb === $this->engine;
    }

    private static function draftOf(TableDefinition $table): TableDraft
    {
        return new TableDraft(
            $table->name,
            array_map(static fn ($c): ColumnDraft => new ColumnDraft($c->name, $c->name, $c->type, $c->nullable, $c->default, $c->autoIncrement), $table->columns),
            array_map(static fn ($i): IndexDraft => new IndexDraft($i->name, $i->name, $i->columns, $i->unique, $i->primary, null, null), $table->indexes),
            array_map(static fn ($f): ForeignKeyDraft => new ForeignKeyDraft($f->name, $f->name, $f->columns, $f->referencedTable, $f->referencedColumns, $f->onUpdate, $f->onDelete), $table->foreignKeys),
        );
    }
}

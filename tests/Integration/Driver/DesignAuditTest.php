<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\TargetConnection;
use App\Core\Optimizer\Finding;
use App\Core\Optimizer\FindingRule;
use App\Core\Optimizer\SchemaAuditor;
use App\Core\Schema\Change\DdlStatement;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use App\Driver\PostgreSql\PostgreSqlDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Les règles de conception rejouées contre les vrais moteurs : des tables jetables (préfixe `tdb_dsg_`, supprimées à la fin)
 * portent chaque défaut, et le schéma `shop` ne doit rien dire de plus.
 */
#[Group('integration')]
final class DesignAuditTest extends TestCase
{
    private const DESIGN_RULES = [
        FindingRule::LegacyCharacterSet,
        FindingRule::ImplicitForeignKey,
        FindingRule::CollationMismatch,
        FindingRule::TwinTable,
        FindingRule::CopyWithoutIndexes,
        FindingRule::TimestampDeadline,
    ];

    private ?TargetConnection $target = null;
    private Engine $engine;

    /** @var list<string> */
    private array $tables = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            try {
                $this->raw('DROP TABLE IF EXISTS '.$this->q($table));
            } catch (\Throwable) {
            }
        }
        $this->target?->close();
    }

    private function open(string $env): void
    {
        $this->engine = TargetDatabase::engine($env);
        $parameters = TargetDatabase::parameters($env);
        try {
            $this->target = Engine::PostgreSql === $this->engine
                ? (new PostgreSqlDriver(new DbalConnector()))->connect($this->engine, $parameters)
                : (new MySqlDriver(new DbalConnector()))->connect($this->engine, $parameters);
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped('The target is unreachable (docker compose --profile targets up -d --wait).');
        }
    }

    private function q(string $name): string
    {
        return Engine::PostgreSql === $this->engine ? '"'.$name.'"' : '`'.$name.'`';
    }

    private function raw(string $sql): void
    {
        \assert(null !== $this->target);
        $this->target->ddl()->execute([new DdlStatement($sql, 'raw', 'raw', false)], false);
    }

    /** Une table jetable : le nom est suivi pour être supprimé. */
    private function scratch(string $stem): string
    {
        return $this->tables[] = 'tdb_dsg_'.$stem;
    }

    /**
     * @return list<TableDefinition>
     */
    private function definitions(): array
    {
        \assert(null !== $this->target);
        $introspector = $this->target->introspector();
        $definitions = [];
        foreach ($introspector->listTables() as $summary) {
            if (TableKind::Table === $summary->kind) {
                $definitions[] = $introspector->describeTable($summary->name);
            }
        }

        return $definitions;
    }

    /**
     * @return list<Finding>
     */
    private function findings(FindingRule $rule, ?string $table = null): array
    {
        return array_values(array_filter(
            (new SchemaAuditor())->audit($this->engine, $this->definitions()),
            static fn (Finding $f): bool => $f->rule === $rule && (null === $table || $f->table === $table),
        ));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mysqlFamily(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL'];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL'];
        yield 'MariaDB' => ['TARGET_MARIADB_URL'];
        yield 'MariaDB 13' => ['TARGET_MARIADB13_URL'];
    }

    #[DataProvider('mysqlFamily')]
    public function testLegacyCharacterSetIsFoundOnARealTable(string $env): void
    {
        $this->open($env);
        $legacy = $this->scratch('legacy');
        $this->raw(\sprintf('CREATE TABLE %s (id int NOT NULL PRIMARY KEY, name varchar(20) NOT NULL, tag varchar(5) NOT NULL) DEFAULT CHARSET=utf8mb3', $this->q($legacy)));

        $findings = $this->findings(FindingRule::LegacyCharacterSet, $legacy);

        self::assertCount(1, $findings);
        self::assertSame(['name', 'tag'], $findings[0]->columns);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function relational(): iterable
    {
        yield from self::mysqlFamily();
        yield 'PostgreSQL' => ['TARGET_POSTGRES_URL'];
    }

    #[DataProvider('relational')]
    public function testAnImplicitKeyIsFoundUntilItIsDeclared(string $env): void
    {
        $this->open($env);
        $customers = $this->scratch('customers');
        $orders = $this->scratch('orders');
        $declared = $this->scratch('invoices');
        $this->raw(\sprintf('CREATE TABLE %s (id integer NOT NULL PRIMARY KEY, label varchar(20))', $this->q($customers)));
        $this->raw(\sprintf('CREATE TABLE %s (id integer NOT NULL PRIMARY KEY, tdb_dsg_customer_id integer NOT NULL)', $this->q($orders)));
        $this->raw(\sprintf('CREATE TABLE %s (id integer NOT NULL PRIMARY KEY, tdb_dsg_customer_id integer NOT NULL, FOREIGN KEY (tdb_dsg_customer_id) REFERENCES %s (id))', $this->q($declared), $this->q($customers)));

        $found = $this->findings(FindingRule::ImplicitForeignKey);

        self::assertCount(1, $found);
        self::assertSame($orders, $found[0]->table);
        self::assertSame($customers.'(id)', $found[0]->detail);
        // La suggestion se rejoue telle quelle : le moteur l'accepte, et la règle se tait ensuite.
        self::assertNotNull($found[0]->suggestion);
        $this->raw($found[0]->suggestion);
        self::assertSame([], $this->findings(FindingRule::ImplicitForeignKey));
    }

    #[DataProvider('mysqlFamily')]
    public function testJoinedColumnsOfDifferentCollationsAreFoundOnAnImplicitRelation(string $env): void
    {
        $this->open($env);
        $users = $this->scratch('users');
        $implicit = $this->scratch('posts');
        $this->raw(\sprintf('CREATE TABLE %s (id varchar(10) COLLATE utf8mb4_bin NOT NULL PRIMARY KEY) DEFAULT CHARSET=utf8mb4', $this->q($users)));
        $this->raw(\sprintf('CREATE TABLE %s (n int NOT NULL PRIMARY KEY, tdb_dsg_user_id varchar(10) COLLATE utf8mb4_general_ci NOT NULL) DEFAULT CHARSET=utf8mb4', $this->q($implicit)));

        $found = $this->findings(FindingRule::CollationMismatch);

        self::assertCount(1, $found);
        self::assertSame($implicit, $found[0]->table);
        self::assertSame('utf8mb4_general_ci ≠ utf8mb4_bin ('.$users.'.id)', $found[0]->detail);
    }

    #[DataProvider('relational')]
    public function testACreateTableAsSelectCopyIsATwinWithoutIndexes(string $env): void
    {
        $this->open($env);
        $orders = $this->scratch('orders');
        $copy = $this->scratch('orders_bak');
        $this->raw(\sprintf('CREATE TABLE %s (id integer NOT NULL PRIMARY KEY, label varchar(20) NOT NULL, note text)', $this->q($orders)));
        $this->raw(\sprintf('CREATE INDEX %s ON %s (label)', $this->q('tdb_dsg_idx_label'), $this->q($orders)));
        $this->raw(\sprintf('CREATE TABLE %s AS SELECT * FROM %s', $this->q($copy), $this->q($orders)));

        foreach ([FindingRule::TwinTable, FindingRule::CopyWithoutIndexes] as $rule) {
            $found = $this->findings($rule);
            self::assertCount(1, $found, $rule->value);
            self::assertSame($copy, $found[0]->table);
            self::assertSame($orders, $found[0]->detail);
        }
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function timestampLimits(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL', true];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL', true];
        yield 'MariaDB' => ['TARGET_MARIADB_URL', false];
        yield 'MariaDB 13' => ['TARGET_MARIADB13_URL', false];
    }

    /**
     * La preuve de la règle : seul MySQL refuse une échéance en 2040 dans un TIMESTAMP, et seul MySQL est donc signalé.
     */
    #[DataProvider('timestampLimits')]
    public function testOnlyMySqlStopsATimestampAt2038AndOnlyMySqlIsFlagged(string $env, bool $mysql): void
    {
        $this->open($env);
        $licences = $this->scratch('licences');
        $this->raw(\sprintf('CREATE TABLE %s (id int NOT NULL PRIMARY KEY, expires_at timestamp NULL DEFAULT NULL)', $this->q($licences)));

        $rejected = false;
        try {
            $this->raw(\sprintf("INSERT INTO %s (id, expires_at) VALUES (1, '2040-06-01 12:00:00')", $this->q($licences)));
        } catch (\Throwable) {
            $rejected = true;
        }

        self::assertSame($mysql, $rejected);
        self::assertCount($mysql ? 1 : 0, $this->findings(FindingRule::TimestampDeadline));
    }

    #[DataProvider('mysqlFamily')]
    public function testShopSaysNothingMoreAndPostgreSqlIsUntouched(string $env): void
    {
        $this->open($env);

        foreach (self::DESIGN_RULES as $rule) {
            self::assertSame([], $this->findings($rule), $rule->value);
        }
    }

    public function testPostgreSqlSaysNothingForTheDesignRules(): void
    {
        $this->open('TARGET_POSTGRES_URL');

        foreach (self::DESIGN_RULES as $rule) {
            self::assertSame([], $this->findings($rule), $rule->value);
        }
    }
}

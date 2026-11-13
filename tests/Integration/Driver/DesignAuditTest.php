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

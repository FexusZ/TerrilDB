<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Optimizer\LintFinding;
use App\Core\Optimizer\QueryLinter;
use App\Core\Schema\SchemaIntrospector;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlIntrospector;
use App\Driver\PostgreSql\PostgreSqlIntrospector;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Le linter croisé avec la structure RÉELLE de tables jetables : l'index présent donne le ton « il ne servira pas », l'index retiré
 * fait taire la règle. Le schéma partagé n'est jamais touché.
 */
#[Group('integration')]
final class QueryLintSchemaTest extends TestCase
{
    private const TABLE = 'lint_probe';

    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield from TargetDatabase::relational();
    }

    private function connect(string $envVar): Connection
    {
        try {
            return (new DbalConnector())->connect(TargetDatabase::engine($envVar), TargetDatabase::parameters($envVar));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped(\sprintf('%s is unreachable (docker compose --profile targets up -d --wait).', $envVar));
        }
    }

    private static function introspector(Engine $engine, Connection $connection): SchemaIntrospector
    {
        return Engine::PostgreSql === $engine ? new PostgreSqlIntrospector($connection) : new MySqlIntrospector($connection);
    }

    private static function create(Connection $connection): void
    {
        $connection->executeStatement('DROP TABLE IF EXISTS '.self::TABLE);
        $connection->executeStatement('CREATE TABLE '.self::TABLE.' (id INTEGER NOT NULL PRIMARY KEY, code VARCHAR(20), note TEXT, qty INTEGER, made TIMESTAMP NULL)');
        $connection->executeStatement('CREATE INDEX idx_probe_code ON '.self::TABLE.' (code)');
        $connection->executeStatement('CREATE INDEX idx_probe_made ON '.self::TABLE.' (made)');
    }

    /**
     * @return array<string, LintFinding> par règle
     */
    private static function lint(Engine $engine, SchemaIntrospector $introspector, string $sql): array
    {
        $found = [];
        foreach ((new QueryLinter())->lint($engine, $sql, static fn (string $name) => $introspector->describeTable($name)) as $finding) {
            $found[$finding->rule->value] = $finding;
        }

        return $found;
    }

    #[DataProvider('targets')]
    public function testTheIndexPresentGivesTheStrongTextAndTheIndexRemovedSilencesIt(string $envVar): void
    {
        $engine = TargetDatabase::engine($envVar);
        $connection = $this->connect($envVar);
        $introspector = self::introspector($engine, $connection);

        try {
            self::create($connection);

            $withIndex = self::lint($engine, $introspector, "SELECT id FROM lint_probe WHERE LOWER(code) = 'x' AND qty + 1 = 3 AND note LIKE '%x'");
            $likeOnIndexed = self::lint($engine, $introspector, "SELECT id FROM lint_probe WHERE code LIKE '%x'");
            $or = self::lint($engine, $introspector, "SELECT id FROM lint_probe WHERE qty = 1 OR code = 'a'");
            $mismatch = self::lint($engine, $introspector, 'SELECT id FROM lint_probe WHERE code = 5');
            $mismatchPlain = self::lint($engine, $introspector, 'SELECT id FROM lint_probe WHERE note = 5');
            $connection->executeStatement('DROP INDEX idx_probe_code'.(Engine::PostgreSql === $engine ? '' : ' ON '.self::TABLE));
            $withoutIndex = self::lint($engine, $introspector, "SELECT id FROM lint_probe WHERE LOWER(code) = 'x'");
            $orWithoutIndex = self::lint($engine, $introspector, "SELECT id FROM lint_probe WHERE qty = 1 OR code = 'a'");
        } finally {
            $connection->executeStatement('DROP TABLE IF EXISTS '.self::TABLE);
            $connection->close();
        }

        // code est indexée en tête, qty et note ne le sont pas : seule la fonction sur code est dite « ne servira pas ».
        self::assertSame(['function_on_column'], array_keys($withIndex));
        self::assertStringContainsString('idx_probe_code', $withIndex['function_on_column']->message);
        self::assertStringContainsString('ne pourra pas servir', $withIndex['function_on_column']->message);

        self::assertArrayHasKey('leading_wildcard_like', $likeOnIndexed);
        self::assertStringContainsString('idx_probe_code', $likeOnIndexed['leading_wildcard_like']->message);
        self::assertSame(Engine::PostgreSql === $engine, str_contains($likeOnIndexed['leading_wildcard_like']->message, 'pg_trgm'));

        self::assertArrayHasKey('or_on_columns', $or);
        self::assertSame(['qty'], $or['or_on_columns']->columns);

        self::assertArrayHasKey('type_mismatch', $mismatch);
        self::assertArrayHasKey('type_mismatch', $mismatchPlain);
        // MySQL/MariaDB convertissent (et perdent l'index) ; PostgreSQL refuse la comparaison.
        self::assertSame(Engine::PostgreSql !== $engine, str_contains($mismatch['type_mismatch']->message, 'idx_probe_code'));
        self::assertStringNotContainsString('idx_probe', $mismatchPlain['type_mismatch']->message);

        self::assertSame([], $withoutIndex);
        self::assertSame([], $orWithoutIndex);
    }

    public function testRowEstimatesDriveTheCountAndRandomOrderRulesOnPostgreSql(): void
    {
        $envVar = 'TARGET_POSTGRES_URL';
        $connection = $this->connect($envVar);
        $introspector = self::introspector(Engine::PostgreSql, $connection);

        try {
            self::create($connection);
            $small = self::lint(Engine::PostgreSql, $introspector, 'SELECT COUNT(*) FROM lint_probe');
            $connection->executeStatement('INSERT INTO '.self::TABLE.' (id, code, qty) SELECT g, \'c\' || g, g FROM generate_series(1, 1100000) AS g');
            $connection->executeStatement('ANALYZE '.self::TABLE);
            $count = self::lint(Engine::PostgreSql, $introspector, 'SELECT COUNT(*) FROM lint_probe');
            $random = self::lint(Engine::PostgreSql, $introspector, 'SELECT id FROM lint_probe ORDER BY random() LIMIT 1');
            $filtered = self::lint(Engine::PostgreSql, $introspector, 'SELECT COUNT(*) FROM lint_probe WHERE qty = 3');
        } finally {
            $connection->executeStatement('DROP TABLE IF EXISTS '.self::TABLE);
            $connection->close();
        }

        self::assertSame([], $small);
        self::assertArrayHasKey('count_star_large', $count);
        self::assertStringContainsString('1 100 000', $count['count_star_large']->message);
        self::assertArrayHasKey('random_order', $random);
        self::assertSame([], $filtered);
    }
}

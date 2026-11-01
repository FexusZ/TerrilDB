<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\SkippedWithMessageException;

/**
 * Les bases cibles de test, décrites par les variables d'environnement de compose.yaml : aucune information
 * de connexion n'est écrite dans les tests.
 */
final class TargetDatabase
{
    private const ENGINES = [
        'TARGET_MYSQL_URL' => Engine::MySql,
        'TARGET_MARIADB_URL' => Engine::MariaDb,
        'TARGET_POSTGRES_URL' => Engine::PostgreSql,
        'TARGET_CLICKHOUSE_URL' => Engine::ClickHouse,
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function all(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL'];
        yield 'MariaDB' => ['TARGET_MARIADB_URL'];
        yield 'PostgreSQL' => ['TARGET_POSTGRES_URL'];
    }

    public static function engine(string $envVar): Engine
    {
        return self::ENGINES[$envVar] ?? throw new \InvalidArgumentException('Unknown target.');
    }

    public static function parameters(string $envVar): ConnectionParameters
    {
        $url = getenv($envVar);
        if (false === $url || '' === $url) {
            throw new SkippedWithMessageException(\sprintf('%s is not set.', $envVar));
        }

        /** @var array<string, mixed> $parts */
        $parts = (new DsnParser(['pdo-mysql' => 'pdo_mysql', 'pdo-pgsql' => 'pdo_pgsql', 'clickhouse' => 'pdo_mysql']))->parse($url);

        return new ConnectionParameters(
            self::part($parts, 'host'),
            (int) self::part($parts, 'port'),
            self::part($parts, 'dbname'),
            self::part($parts, 'user'),
            self::part($parts, 'password'),
        );
    }

    /**
     * @param array<string, mixed> $parts
     */
    private static function part(array $parts, string $key): string
    {
        $value = $parts[$key] ?? null;
        Assert::assertTrue(\is_scalar($value), \sprintf('The target URL has no "%s".', $key));

        return (string) $value;
    }
}

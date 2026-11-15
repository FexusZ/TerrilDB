<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\ConnectionParameters;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Driver\DbalConnector;
use Doctrine\DBAL\Exception\ConnectionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class DbalConnectorTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield from TargetDatabase::relational();
    }

    private function skipIfUnreachable(string $envVar): void
    {
        try {
            (new DbalConnector())->connect(TargetDatabase::engine($envVar), TargetDatabase::parameters($envVar))->fetchOne('SELECT 1');
        } catch (TargetConnectionFailedException|ConnectionException) {
            self::markTestSkipped(\sprintf('%s is unreachable (docker compose --profile targets up -d --wait).', $envVar));
        }
    }

    #[DataProvider('targets')]
    public function testConnectsAndAnswersQueries(string $envVar): void
    {
        $this->skipIfUnreachable($envVar);

        $connection = (new DbalConnector())->connect(TargetDatabase::engine($envVar), TargetDatabase::parameters($envVar));

        self::assertEquals(1, $connection->fetchOne('SELECT 1'));
    }

    #[DataProvider('targets')]
    public function testAWrongPasswordIsRefusedWithoutEchoingIt(string $envVar): void
    {
        $this->skipIfUnreachable($envVar);
        $parameters = TargetDatabase::parameters($envVar)->withPassword('wrong-secret-value');

        try {
            (new DbalConnector())->connect(TargetDatabase::engine($envVar), $parameters);
            self::fail('Expected a refusal.');
        } catch (TargetConnectionFailedException $e) {
            self::assertStringNotContainsString('wrong-secret-value', $e->getMessage());
        }
    }

    #[DataProvider('targets')]
    public function testAnUnknownDatabaseIsRefused(string $envVar): void
    {
        $this->skipIfUnreachable($envVar);
        $real = TargetDatabase::parameters($envVar);
        $parameters = new ConnectionParameters($real->host, $real->port, 'no_such_database', $real->username, $real->password());

        $this->expectException(TargetConnectionFailedException::class);

        (new DbalConnector())->connect(TargetDatabase::engine($envVar), $parameters);
    }

    #[DataProvider('targets')]
    public function testAClosedPortIsRefused(string $envVar): void
    {
        $this->skipIfUnreachable($envVar);
        $real = TargetDatabase::parameters($envVar);
        $parameters = new ConnectionParameters($real->host, 1, $real->database, $real->username, $real->password());

        $this->expectException(TargetConnectionFailedException::class);

        (new DbalConnector())->connect(TargetDatabase::engine($envVar), $parameters);
    }

    /**
     * Le garde lit les chaînes avec l'échappement par antislash (MySQL par défaut) : un serveur réglé sur NO_BACKSLASH_ESCAPES
     * ou ANSI_QUOTES lirait le même texte autrement et cacherait des constructions refusées au garde. La session est donc
     * ramenée au comportement que le garde suppose, quel que soit le réglage du serveur.
     *
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
    public function testTheSessionDropsSqlModesThatChangeHowTextIsRead(string $envVar): void
    {
        $this->skipIfUnreachable($envVar);
        $admin = (new DbalConnector())->connect(TargetDatabase::engine($envVar), TargetDatabase::parameters($envVar));
        $global = $admin->fetchOne('SELECT @@GLOBAL.sql_mode');
        $original = \is_string($global) ? $global : '';

        try {
            $admin->executeStatement(\sprintf('SET GLOBAL sql_mode = %s', $admin->quote(trim($original.',NO_BACKSLASH_ESCAPES,ANSI_QUOTES', ','))));

            $session = (new DbalConnector())->connect(TargetDatabase::engine($envVar), TargetDatabase::parameters($envVar))->fetchOne('SELECT @@SESSION.sql_mode');
            $modes = explode(',', \is_string($session) ? $session : '');
        } finally {
            $admin->executeStatement(\sprintf('SET GLOBAL sql_mode = %s', $admin->quote($original)));
        }

        self::assertNotContains('NO_BACKSLASH_ESCAPES', $modes);
        self::assertNotContains('ANSI_QUOTES', $modes);
    }

    /**
     * Même raison pour PostgreSQL : avec `standard_conforming_strings = off` (réglage de rôle ou de base), l'antislash échappe
     * le guillemet dans '…' pour le moteur mais pas pour le garde. La session impose donc `on`.
     */
    public function testThePostgreSqlSessionKeepsStandardConformingStrings(): void
    {
        $this->skipIfUnreachable('TARGET_POSTGRES_URL');
        $admin = (new DbalConnector())->connect(TargetDatabase::engine('TARGET_POSTGRES_URL'), TargetDatabase::parameters('TARGET_POSTGRES_URL'));
        $user = TargetDatabase::parameters('TARGET_POSTGRES_URL')->username;
        if ([] !== $admin->fetchAllAssociative('SELECT 1 FROM pg_db_role_setting')) {
            self::markTestSkipped('The target already carries role or database settings.');
        }

        try {
            $admin->executeStatement(\sprintf('ALTER ROLE %s SET standard_conforming_strings = off', $admin->quoteSingleIdentifier($user)));

            $value = (new DbalConnector())->connect(TargetDatabase::engine('TARGET_POSTGRES_URL'), TargetDatabase::parameters('TARGET_POSTGRES_URL'))->fetchOne('SHOW standard_conforming_strings');
        } finally {
            $admin->executeStatement(\sprintf('ALTER ROLE %s RESET standard_conforming_strings', $admin->quoteSingleIdentifier($user)));
        }

        self::assertSame('on', $value);
    }

    /**
     * Un jeu de caractères client multi-octets (SJIS, BIG5, GBK…) peut contenir l'octet de l'antislash dans un caractère : dans
     * E'…' le moteur lirait alors un échappement que le garde ne voit pas. La session PostgreSQL impose UTF8.
     */
    public function testThePostgreSqlSessionReadsTextAsUtf8(): void
    {
        $this->skipIfUnreachable('TARGET_POSTGRES_URL');
        $admin = (new DbalConnector())->connect(TargetDatabase::engine('TARGET_POSTGRES_URL'), TargetDatabase::parameters('TARGET_POSTGRES_URL'));
        $user = TargetDatabase::parameters('TARGET_POSTGRES_URL')->username;
        if ([] !== $admin->fetchAllAssociative('SELECT 1 FROM pg_db_role_setting')) {
            self::markTestSkipped('The target already carries role or database settings.');
        }

        try {
            $admin->executeStatement(\sprintf("ALTER ROLE %s SET client_encoding = 'SJIS'", $admin->quoteSingleIdentifier($user)));

            $value = (new DbalConnector())->connect(TargetDatabase::engine('TARGET_POSTGRES_URL'), TargetDatabase::parameters('TARGET_POSTGRES_URL'))->fetchOne('SHOW client_encoding');
        } finally {
            $admin->executeStatement(\sprintf('ALTER ROLE %s RESET client_encoding', $admin->quoteSingleIdentifier($user)));
        }

        self::assertSame('UTF8', $value);
    }
}

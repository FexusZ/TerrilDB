<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Driver\DbalConnector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Chaque cible MySQL / MariaDB tient une série précise : la plus ancienne qu'on supporte et la plus récente. Sans ce
 * test, une mise à jour automatique d'image (Dependabot) ferait glisser une cible vers une autre série sans que
 * personne le remarque, et une série ne serait plus essayée du tout.
 */
#[Group('integration')]
final class TargetVersionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, non-empty-string}>
     */
    public static function targets(): iterable
    {
        yield 'MySQL 8.4 (support long)' => ['TARGET_MYSQL_URL', '8.4.'];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL', '26.'];
        yield 'MariaDB 11.8 (support long)' => ['TARGET_MARIADB_URL', '11.8.'];
        yield 'MariaDB 13' => ['TARGET_MARIADB13_URL', '13.'];
    }

    /**
     * @param non-empty-string $series
     */
    #[DataProvider('targets')]
    public function testTheTargetRunsTheSeriesItsNameAnnounces(string $envVar, string $series): void
    {
        $connection = (new DbalConnector())->connect(TargetDatabase::engine($envVar), TargetDatabase::parameters($envVar));

        try {
            $version = $connection->fetchOne('SELECT VERSION()');
        } finally {
            $connection->close();
        }

        self::assertIsString($version);
        self::assertStringStartsWith($series, $version);
    }
}

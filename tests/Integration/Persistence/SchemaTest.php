<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

#[Group('integration')]
final class SchemaTest extends DatabaseTestCase
{
    public function testTheMigratedDatabaseMatchesTheEntityMapping(): void
    {
        // ApplicationTester (et non CommandTester) : seul le cycle complet de l'application déclenche
        // l'écouteur qui masque la table interne des migrations.
        self::assertNotNull(self::$kernel);
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);

        $status = $tester->run(['command' => 'doctrine:schema:validate']);

        self::assertSame(0, $status, $tester->getDisplay());
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base des tests d'intégration sur la base interne (docker compose up db, puis les migrations en
 * environnement de test). Chaque test s'exécute dans une transaction annulée à la fin : la base reste vide.
 */
abstract class DatabaseTestCase extends KernelTestCase
{
    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $connection = $this->entityManager->getConnection();

        try {
            $connection->executeQuery('SELECT 1');
        } catch (ConnectionException) {
            self::markTestSkipped('The internal database is unreachable (docker compose up -d --wait db).');
        }

        if (!$connection->createSchemaManager()->tableExists('app_user')) {
            self::fail('The test schema is missing: php bin/console doctrine:migrations:migrate --env=test');
        }

        $connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager)) {
            $connection = $this->entityManager->getConnection();
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }

        parent::tearDown();
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Deux administrateurs qui se désactivent en même temps : vrais sous-processus, vraies transactions validées
 * (une connexion à part, hors de la transaction annulée des autres tests) ; un seul des deux doit réussir.
 */
#[Group('integration')]
final class AccountAdministrationRaceTest extends TestCase
{
    private const HASH = '$argon2id$v=19$m=16384,t=3,p=1$c29tZXNhbHQ$aGFzaGhhc2hoYXNoaGFzaA';
    private const A = '01900000-0000-7000-8000-00000000000a';
    private const B = '01900000-0000-7000-8000-00000000000b';

    private Connection $db;

    protected function setUp(): void
    {
        $url = $_SERVER['DATABASE_URL'] ?? null;
        if (!\is_string($url) || '' === $url) {
            self::markTestSkipped('DATABASE_URL is not set.');
        }
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $params['dbname'] = ($params['dbname'] ?? '').'_test'.(\is_string($_SERVER['TEST_TOKEN'] ?? null) ? $_SERVER['TEST_TOKEN'] : '');
        try {
            $this->db = DriverManager::getConnection($params);
            $this->db->executeQuery('SELECT 1');
        } catch (\Throwable) {
            self::markTestSkipped('The internal database is unreachable.');
        }
        $this->cleanUp();
        foreach ([self::A => 'racea', self::B => 'raceb'] as $id => $name) {
            $this->db->executeStatement(
                "INSERT INTO app_user (id, email, username, password_hash, kdf_parameters, recovery_code_hashes, created_at, role, version) VALUES (?, ?, ?, ?, 'x', '[]', NOW(), 'admin', 1)",
                [$id, $name.'@race.test', $name, self::HASH],
            );
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanUp();
        }
    }

    private function cleanUp(): void
    {
        $this->db->executeStatement("DELETE FROM app_user WHERE email LIKE '%@race.test'");
    }

    public function testTwoAdministratorsDisablingEachOtherLeaveOneActive(): void
    {
        // Verrou tenu le temps de lancer les deux processus : ils arrivent ensemble devant la même décision.
        $this->db->beginTransaction();
        $this->db->executeQuery("SELECT id FROM app_user WHERE role = 'admin' AND disabled_at IS NULL ORDER BY id FOR UPDATE")->fetchAllAssociative();

        $script = \dirname(__DIR__).'/Support/disable_account.php';
        $children = [
            $this->start($script, self::A, self::B),
            $this->start($script, self::B, self::A),
        ];
        usleep(1_500_000);
        $this->db->commit();

        $results = array_map($this->finish(...), $children);
        sort($results);

        self::assertSame(['disabled', 'last_administrator'], $results);
        $active = $this->db->fetchOne("SELECT COUNT(*) FROM app_user WHERE email LIKE '%@race.test' AND disabled_at IS NULL");
        self::assertEquals(1, $active);
    }

    /**
     * @return array{resource, resource}
     */
    private function start(string $script, string $admin, string $target): array
    {
        $process = proc_open([\PHP_BINARY, $script, $admin, $target], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        return [$process, $pipes[1]];
    }

    /**
     * @param array{resource, resource} $child
     */
    private function finish(array $child): string
    {
        $output = trim((string) stream_get_contents($child[1]));
        proc_close($child[0]);

        return $output;
    }
}

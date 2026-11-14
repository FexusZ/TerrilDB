<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\SkippedWithMessageException;
use PHPUnit\Framework\TestCase;

/**
 * Le vrai cluster Vitess de test (profil « vitess-cluster ») : un tablet PRIMARY et un tablet REPLICA, chacun avec son
 * propre mysqld réellement répliqué. Sauté si le cluster n'est pas démarré. Voir docs/vitess-tests.md.
 */
#[Group('integration')]
final class TargetVitessClusterTest extends TestCase
{
    private \PDO $primary;
    private \PDO $replica;

    protected function setUp(): void
    {
        $url = getenv('TARGET_VITESS_CLUSTER_URL');
        if (false === $url || '' === $url) {
            throw new SkippedWithMessageException('TARGET_VITESS_CLUSTER_URL is not set.');
        }
        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['host'], $parts['port'])) {
            throw new SkippedWithMessageException('TARGET_VITESS_CLUSTER_URL is malformed.');
        }

        try {
            $this->primary = $this->open($parts['host'], $parts['port'], 'shop@primary');
            $this->replica = $this->open($parts['host'], $parts['port'], 'shop@replica');
        } catch (\PDOException) {
            throw new SkippedWithMessageException('The Vitess cluster is unreachable.');
        }
    }

    public function testThePrimaryAndTheReplicaAreTwoDistinctMysqlServers(): void
    {
        self::assertNotSame($this->scalar($this->primary, 'SELECT @@server_id'), $this->scalar($this->replica, 'SELECT @@server_id'));
        self::assertNotSame($this->scalar($this->primary, 'SELECT @@server_uuid'), $this->scalar($this->replica, 'SELECT @@server_uuid'));
    }

    public function testTheDatasetIsTheSameAsTheOtherTargets(): void
    {
        self::assertSame('7000', $this->scalar($this->primary, 'SELECT COUNT(*) FROM order_items'));
        self::assertSame('7000', $this->scalar($this->replica, 'SELECT COUNT(*) FROM order_items'));
    }

    public function testAWriteOnThePrimaryReachesTheReplicaByRealReplication(): void
    {
        $this->primary->exec('CREATE TABLE IF NOT EXISTS zz_replication_probe (id INT PRIMARY KEY, note VARCHAR(20))');

        try {
            $this->primary->exec("REPLACE INTO zz_replication_probe (id, note) VALUES (1, 'replicated')");

            $seen = null;
            for ($attempt = 0; $attempt < 50 && 'replicated' !== $seen; ++$attempt) {
                usleep(100_000);
                try {
                    $seen = $this->scalar($this->replica, 'SELECT note FROM zz_replication_probe WHERE id = 1');
                } catch (\PDOException) {
                    $seen = null; // la table n'est pas encore arrivée
                }
            }
            self::assertSame('replicated', $seen);
        } finally {
            $this->primary->exec('DROP TABLE IF EXISTS zz_replication_probe');
        }
    }

    private function open(string $host, int $port, string $keyspace): \PDO
    {
        return new \PDO(\sprintf('mysql:host=%s;port=%d;dbname=%s', $host, $port, $keyspace), 'root', '', [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => 3,
        ]);
    }

    private function scalar(\PDO $pdo, string $sql): string
    {
        $statement = $pdo->query($sql);
        self::assertInstanceOf(\PDOStatement::class, $statement);
        $value = $statement->fetchColumn();
        self::assertTrue(\is_scalar($value));

        return (string) $value;
    }
}

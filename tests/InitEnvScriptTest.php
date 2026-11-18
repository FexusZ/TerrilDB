<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/** Le secret du framework en développement est tiré au hasard par docker/init-env.sh, jamais versionné. */
final class InitEnvScriptTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/terrildb-init-env-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/docker', 0o700, true);
        copy(\dirname(__DIR__).'/docker/init-env.sh', $this->root.'/docker/init-env.sh');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/docker/dev/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->root.'/docker/dev');
        @unlink($this->root.'/docker/init-env.sh');
        @rmdir($this->root.'/docker');
        @rmdir($this->root);
    }

    public function testFreshRunWritesARandomAppSecret(): void
    {
        $first = $this->generate();
        $second = (new self('x'))->generateIn();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        self::assertNotSame($first, $second);
    }

    public function testSecondRunKeepsTheSecret(): void
    {
        $first = $this->generate();
        $this->execute();

        self::assertSame($first, $this->secret());
    }

    public function testExistingFileWithoutSecretIsCompletedOnce(): void
    {
        mkdir($this->root.'/docker/dev', 0o700);
        file_put_contents($this->root.'/docker/dev/php.env', "DATABASE_URL=x\nTARGET_MYSQL26_URL=x\nTARGET_MARIADB13_URL=x\n");

        $this->execute();
        $added = $this->secret();
        $this->execute();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $added);
        self::assertSame($added, $this->secret());
        self::assertStringContainsString('DATABASE_URL=x', (string) file_get_contents($this->root.'/docker/dev/php.env'));
    }

    public function testFreshRunGivesTheVitessClusterAPassword(): void
    {
        $this->execute();

        $env = (string) file_get_contents($this->root.'/docker/dev/target-vitess.env');
        $password = $this->vitessPassword($env);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9]{24}$/', $password);
        self::assertStringContainsString('TARGET_VITESS_CLUSTER_URL=pdo-mysql://root:'.$password.'@vitess-vtgate:15306/shop', (string) file_get_contents($this->root.'/docker/dev/php.env'));
        self::assertSame(0o600, fileperms($this->root.'/docker/dev/target-vitess.env') & 0o777);
    }

    public function testExistingInstallationGetsAVitessPasswordOnlyWhereTheUrlHadNone(): void
    {
        mkdir($this->root.'/docker/dev', 0o700);
        file_put_contents($this->root.'/docker/dev/php.env', "DATABASE_URL=x\nTARGET_MYSQL26_URL=x\nTARGET_MARIADB13_URL=x\nTARGET_VITESS_URL=x\nTARGET_VITESS_CLUSTER_URL=pdo-mysql://root@vitess-vtgate:15306/shop\n");
        $this->siblingEnvFiles();

        $this->execute();
        $php = (string) file_get_contents($this->root.'/docker/dev/php.env');
        $env = (string) file_get_contents($this->root.'/docker/dev/target-vitess.env');
        $password = $this->vitessPassword($env);

        self::assertStringContainsString('TARGET_VITESS_CLUSTER_URL=pdo-mysql://root:'.$password.'@vitess-vtgate:15306/shop', $php);
        self::assertStringContainsString('DATABASE_URL=x', $php);

        $this->execute();
        self::assertSame($php, (string) file_get_contents($this->root.'/docker/dev/php.env'));
        self::assertSame($env, (string) file_get_contents($this->root.'/docker/dev/target-vitess.env'));
    }

    public function testExistingVitessPasswordIsNeverRewritten(): void
    {
        mkdir($this->root.'/docker/dev', 0o700);
        file_put_contents($this->root.'/docker/dev/php.env', "TARGET_MYSQL26_URL=x\nTARGET_MARIADB13_URL=x\nTARGET_VITESS_URL=x\nTARGET_VITESS_CLUSTER_URL=pdo-mysql://root:mine@vitess-vtgate:15306/shop\n");
        $this->siblingEnvFiles();
        file_put_contents($this->root.'/docker/dev/target-vitess.env', "VITESS_ROOT_PASSWORD=mine\n");

        $this->execute();

        self::assertStringContainsString("TARGET_VITESS_CLUSTER_URL=pdo-mysql://root:mine@vitess-vtgate:15306/shop\n", (string) file_get_contents($this->root.'/docker/dev/php.env'));
        self::assertSame("VITESS_ROOT_PASSWORD=mine\n", (string) file_get_contents($this->root.'/docker/dev/target-vitess.env'));
    }

    public function testNoSecretIsVersioned(): void
    {
        $dev = \dirname(__DIR__).'/.env.dev';
        self::assertTrue(!is_file($dev) || !preg_match('/^APP_SECRET=./m', (string) file_get_contents($dev)));
    }

    private function vitessPassword(string $env): string
    {
        if (1 !== preg_match('/^VITESS_ROOT_PASSWORD=(.+)$/m', $env, $m)) {
            self::fail('VITESS_ROOT_PASSWORD absent.');
        }

        return $m[1];
    }

    private function siblingEnvFiles(): void
    {
        file_put_contents($this->root.'/docker/dev/target-mysql.env', "MYSQL_ROOT_PASSWORD=a\n");
        file_put_contents($this->root.'/docker/dev/target-mariadb.env', "MARIADB_ROOT_PASSWORD=b\n");
    }

    private const REPLICATION_VARIABLES = [
        'TARGET_REPLICATED_POSTGRES_URL' => ['replication-postgres.env', 'POSTGRES_PASSWORD', 'pdo-pgsql://postgres:%s@repl-pg-primary:5432/postgres'],
        'TARGET_REPLICA_POSTGRES_URL' => ['replication-postgres.env', 'POSTGRES_PASSWORD', 'pdo-pgsql://postgres:%s@repl-pg-replica:5432/postgres'],
        'TARGET_REPLICA_MYSQL_URL' => ['replication-mysql.env', 'MYSQL_ROOT_PASSWORD', 'pdo-mysql://root:%s@repl-mysql-replica:3306/mysql'],
        'TARGET_SEMISYNC_MYSQL_URL' => ['replication-mysql.env', 'MYSQL_ROOT_PASSWORD', 'pdo-mysql://root:%s@repl-mysql-semisync:3306/mysql'],
        'TARGET_REPLICA_MARIADB_URL' => ['replication-mariadb.env', 'MARIADB_ROOT_PASSWORD', 'pdo-mysql://root:%s@repl-mariadb-replica:3306/mysql'],
        'TARGET_BINLOG_MARIADB_URL' => ['replication-mariadb.env', 'MARIADB_ROOT_PASSWORD', 'pdo-mysql://root:%s@repl-mariadb-binlog:3306/mysql'],
        'TARGET_REPLICATED_CLICKHOUSE_URL' => ['replication-clickhouse.env', 'CLICKHOUSE_PASSWORD', 'clickhouse://tester:%s@repl-clickhouse:8123/d'],
        'TARGET_REPLICATED_REDIS_URL' => ['replication-redis.env', 'REDIS_PASSWORD', 'redis://default:%s@repl-redis-primary:6379/15'],
        'TARGET_REPLICA_REDIS_URL' => ['replication-redis.env', 'REDIS_PASSWORD', 'redis://default:%s@repl-redis-replica:6379/15'],
    ];

    /** Le profil « replication » : mots de passe propres, tirés au hasard, jamais versionnés ; les URL des tests suivent. */
    public function testFreshRunWritesTheReplicationPasswordsAndUrls(): void
    {
        $this->execute();

        $php = $this->envFile('php.env');
        foreach (self::REPLICATION_VARIABLES as $variable => [$file, $key, $format]) {
            $password = $this->envFile($file)[$key] ?? '';
            self::assertMatchesRegularExpression('/^[A-Za-z0-9]{24}$/', $password, $file);
            self::assertSame(\sprintf($format, $password), $php[$variable] ?? null, $variable);
        }
        self::assertSame('tester', $this->envFile('replication-clickhouse.env')['CLICKHOUSE_USER']);
        self::assertSame('1', $this->envFile('replication-clickhouse.env')['CLICKHOUSE_DEFAULT_ACCESS_MANAGEMENT']);
        self::assertNotSame($this->envFile('replication-mysql.env')['MYSQL_ROOT_PASSWORD'], $this->envFile('target-mysql.env')['MYSQL_ROOT_PASSWORD']);
        self::assertSame('0600', substr(\sprintf('%04o', fileperms($this->root.'/docker/dev/replication-redis.env')), -4));
    }

    public function testExistingInstallationGetsTheReplicationSetupWithoutChangingAnythingElse(): void
    {
        mkdir($this->root.'/docker/dev', 0o700);
        $before = "APP_SECRET=abc\nDATABASE_URL=x\nTARGET_MYSQL26_URL=x\nTARGET_MARIADB13_URL=x\nTARGET_REDIS_URL=keep\n";
        file_put_contents($this->root.'/docker/dev/php.env', $before);
        file_put_contents($this->root.'/docker/dev/target-redis.env', "REDIS_PASSWORD=old\n");

        $this->execute();
        $first = (string) file_get_contents($this->root.'/docker/dev/php.env');
        $passwords = (string) file_get_contents($this->root.'/docker/dev/replication-postgres.env');
        $this->execute();

        self::assertStringStartsWith($before, $first);
        self::assertSame($first, (string) file_get_contents($this->root.'/docker/dev/php.env'), 'A second run adds nothing.');
        self::assertSame($passwords, (string) file_get_contents($this->root.'/docker/dev/replication-postgres.env'));
        self::assertSame("REDIS_PASSWORD=old\n", (string) file_get_contents($this->root.'/docker/dev/target-redis.env'));
        $php = $this->envFile('php.env');
        foreach (array_keys(self::REPLICATION_VARIABLES) as $variable) {
            self::assertArrayHasKey($variable, $php);
        }
    }

    public function testAnExistingReplicationVariableIsNeverRewritten(): void
    {
        mkdir($this->root.'/docker/dev', 0o700);
        file_put_contents($this->root.'/docker/dev/php.env', "APP_SECRET=abc\nTARGET_MYSQL26_URL=x\nTARGET_MARIADB13_URL=x\nTARGET_REPLICA_REDIS_URL=redis://mine\n");

        $this->execute();

        self::assertSame('redis://mine', $this->envFile('php.env')['TARGET_REPLICA_REDIS_URL']);
    }

    /**
     * @return array<string, string>
     */
    private function envFile(string $name): array
    {
        $values = [];
        foreach (explode("\n", (string) file_get_contents($this->root.'/docker/dev/'.$name)) as $line) {
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $values[$key] = $value;
            }
        }

        return $values;
    }

    private function generateIn(): string
    {
        $this->setUp();
        $secret = $this->generate();
        $this->tearDown();

        return $secret;
    }

    private function generate(): string
    {
        $this->execute();

        return $this->secret();
    }

    private function execute(): void
    {
        exec('sh '.escapeshellarg($this->root.'/docker/init-env.sh').' 2>&1', $output, $code);
        self::assertSame(0, $code, implode("\n", $output));
    }

    private function secret(): string
    {
        $found = preg_match('/^APP_SECRET=(.*)$/m', (string) file_get_contents($this->root.'/docker/dev/php.env'), $m);
        if (1 !== $found) {
            self::fail('APP_SECRET absent de php.env.');
        }

        return $m[1];
    }
}

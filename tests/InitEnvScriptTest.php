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

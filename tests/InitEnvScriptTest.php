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

    public function testNoSecretIsVersioned(): void
    {
        $dev = \dirname(__DIR__).'/.env.dev';
        self::assertTrue(!is_file($dev) || !preg_match('/^APP_SECRET=./m', (string) file_get_contents($dev)));
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
        self::assertSame(1, preg_match('/^APP_SECRET=(.*)$/m', (string) file_get_contents($this->root.'/docker/dev/php.env'), $m));

        self::assertArrayHasKey(1, $m);

        return $m[1];
    }
}

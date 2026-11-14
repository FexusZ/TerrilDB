<?php

declare(strict_types=1);

namespace App\Tests\Deploy;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Le délai du reverse proxy et la limite de l'application viennent d'UNE valeur (TERRILDB_PROXY_TIMEOUT) : le gabarit nginx,
 * le compose de production, le déploiement et le contrôle d'accord doivent tous la porter.
 */
final class ProxyTimeoutDeployTest extends TestCase
{
    private const SCRIPT = 'docker/prod/proxy-timeout.sh';

    private static function path(string $relative): string
    {
        return \dirname(__DIR__, 2).'/'.$relative;
    }

    private static function read(string $relative): string
    {
        $content = file_get_contents(self::path($relative));
        self::assertIsString($content);

        return $content;
    }

    private function vhost(string $body): string
    {
        $file = tempnam(sys_get_temp_dir(), 'vhost');
        self::assertIsString($file);
        file_put_contents($file, $body);
        register_shutdown_function(static fn () => @unlink($file));

        return $file;
    }

    /**
     * @return array{int, string}
     */
    private function script(string ...$args): array
    {
        $process = new Process(['sh', self::path(self::SCRIPT), ...$args]);
        $process->run();

        return [(int) $process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
    }

    private const VHOST = <<<'NGINX'
        server {
            server_name example.test;
            location / {
                proxy_pass         http://127.0.0.1:8090;
                proxy_set_header   X-Forwarded-Proto $scheme;
                # Une requête SQL peut durer jusqu'à son délai maximal.
                proxy_read_timeout %s;
            }
        }
        NGINX;

    public function testTheNginxTemplateCarriesTheSharedValueAndTellsTheApplicationAboutIt(): void
    {
        $template = self::read('docker/prod/nginx.conf.template');

        self::assertStringContainsString('proxy_read_timeout __PROXY_TIMEOUT__s;', $template);
        self::assertStringContainsString('proxy_set_header   X-TerrilDB-Proxy-Timeout __PROXY_TIMEOUT__;', $template);
        self::assertStringNotContainsString('proxy_read_timeout 120s', $template);
    }

    public function testTheProductionComposePassesTheValueToTheApplication(): void
    {
        $compose = Yaml::parseFile(self::path('compose.prod.yaml'));
        self::assertIsArray($compose);
        $services = $compose['services'];
        self::assertIsArray($services);
        $app = $services['app'];
        self::assertIsArray($app);
        $environment = $app['environment'];
        self::assertIsArray($environment);

        self::assertSame('${TERRILDB_PROXY_TIMEOUT:-120}', $environment['TERRILDB_PROXY_TIMEOUT']);
    }

    public function testTheDeploymentKeepsTheValueInItsSettingsFile(): void
    {
        $deploy = self::read('docker/prod/deploy.sh');

        self::assertMatchesRegularExpression('/printf [^\n]*TERRILDB_PROXY_TIMEOUT=%s/', $deploy, 'Rewriting deploy.env must not lose the value.');
        self::assertMatchesRegularExpression('/proxy-timeout\.sh"? check/', $deploy, 'The deployment checks that nginx agrees.');
    }

    public function testTheHostSetupRendersTheValueAndSaysHowToSyncAnExistingSite(): void
    {
        $setup = self::read('docker/prod/setup-host.sh');

        self::assertStringContainsString('__PROXY_TIMEOUT__', $setup);
        self::assertStringContainsString('sync-nginx.sh', $setup);
        self::assertFileExists(self::path('docker/prod/sync-nginx.sh'));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function agreeing(): iterable
    {
        yield 'seconds' => ['120s', 120];
        yield 'bare number' => ['120', 120];
        yield 'minutes' => ['2m', 120];
        yield 'hours' => ['1h', 3600];
    }

    #[DataProvider('agreeing')]
    public function testAVhostThatAgreesPasses(string $value, int $seconds): void
    {
        [$code, $output] = $this->script('check', $this->vhost(\sprintf(self::VHOST, $value.';')), (string) $seconds);

        self::assertSame(0, $code, $output);
    }

    public function testAVhostThatDisagreesFailsAndSaysWhichValuesAndHowToFix(): void
    {
        [$code, $output] = $this->script('check', $this->vhost(\sprintf(self::VHOST, '60s;')), '120');

        self::assertSame(1, $code);
        self::assertStringContainsString('60', $output);
        self::assertStringContainsString('120', $output);
        self::assertStringContainsString('sync-nginx.sh', $output);
    }

    public function testAVhostWithoutTheDirectiveCountsAsNginxDefaultOfSixtySeconds(): void
    {
        [$code, $output] = $this->script('check', $this->vhost("server {\n    location / { proxy_pass http://127.0.0.1:8090; }\n}\n"), '120');

        self::assertSame(1, $code);
        self::assertStringContainsString('60', $output);
    }

    public function testAnAbsentVhostIsSkippedNotAFailure(): void
    {
        [$code, $output] = $this->script('check', '/nonexistent/terrildb-vhost', '120');

        self::assertSame(2, $code);
        self::assertStringContainsString('introuvable', $output);
    }

    public function testAnUnreadableValueIsSkippedNotAFailure(): void
    {
        [$code] = $this->script('check', $this->vhost(\sprintf(self::VHOST, '$timeout;')), '120');

        self::assertSame(2, $code);
    }

    public function testApplyRewritesTheDirectiveAndAddsTheHeaderForAnOlderSite(): void
    {
        $file = $this->vhost(\sprintf(self::VHOST, '120s;'));

        [$code, $output] = $this->script('apply', $file, '300');

        self::assertSame(0, $code, $output);
        $content = (string) file_get_contents($file);
        self::assertStringContainsString('proxy_read_timeout 300s;', $content);
        self::assertStringNotContainsString('proxy_read_timeout 120s', $content);
        self::assertStringContainsString('proxy_set_header   X-TerrilDB-Proxy-Timeout 300;', $content);
        self::assertStringContainsString('proxy_pass         http://127.0.0.1:8090;', $content, 'The rest of the site is untouched.');
        [$check] = $this->script('check', $file, '300');
        self::assertSame(0, $check);
    }

    public function testApplyTwiceDoesNotDuplicateTheHeader(): void
    {
        $file = $this->vhost(\sprintf(self::VHOST, '120s;'));

        $this->script('apply', $file, '300');
        $this->script('apply', $file, '200');

        $content = (string) file_get_contents($file);
        self::assertSame(1, substr_count($content, 'X-TerrilDB-Proxy-Timeout'));
        self::assertSame(1, substr_count($content, 'proxy_read_timeout'));
        self::assertStringContainsString('proxy_read_timeout 200s;', $content);
    }

    public function testApplyRefusesAValueTheApplicationWouldNotAccept(): void
    {
        $file = $this->vhost(\sprintf(self::VHOST, '120s;'));

        [$code] = $this->script('apply', $file, '10');

        self::assertSame(64, $code);
        self::assertStringContainsString('proxy_read_timeout 120s;', (string) file_get_contents($file));
    }
}

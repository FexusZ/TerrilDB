<?php

declare(strict_types=1);

namespace App\Tests\Http\Web;

use App\Http\Web\SpaController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class SpaControllerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/terrildb-spa-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        @unlink($this->directory.'/spa.html');
        @rmdir($this->directory);
    }

    public function testServesTheBuiltIndexForAnyApplicationAddress(): void
    {
        file_put_contents($this->directory.'/spa.html', '<!doctype html><div id="app"></div>');
        $controller = new SpaController($this->directory.'/spa.html');

        $response = $controller->app();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('<!doctype html><div id="app"></div>', $response->getContent());
        self::assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
    }

    public function testTheIndexIsNeverCachedSoANewBuildIsPickedUp(): void
    {
        file_put_contents($this->directory.'/spa.html', '<div id="app"></div>');

        $response = (new SpaController($this->directory.'/spa.html'))->app();

        self::assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
    }

    public function testExplainsWhenTheFrontIsNotBuilt(): void
    {
        $response = (new SpaController($this->directory.'/missing.html'))->app();

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertStringContainsString('npm run build', (string) $response->getContent());
    }

    public function testTheRootRedirectsToTheApplication(): void
    {
        $response = (new SpaController($this->directory.'/spa.html'))->root();

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/app/', $response->headers->get('Location'));
    }
}

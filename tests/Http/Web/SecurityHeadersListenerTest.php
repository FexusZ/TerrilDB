<?php

declare(strict_types=1);

namespace App\Tests\Http\Web;

use App\Http\Web\SecurityHeadersListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SecurityHeadersListenerTest extends TestCase
{
    private function handle(string $path, int $type = HttpKernelInterface::MAIN_REQUEST): Response
    {
        $response = new Response('x');
        $event = new ResponseEvent(self::createStub(HttpKernelInterface::class), Request::create($path), $type, $response);
        (new SecurityHeadersListener())($event);

        return $response;
    }

    public function testEveryResponseGetsTheBasicProtections(): void
    {
        foreach (['/api/health', '/web/me', '/app/', '/app/connections'] as $path) {
            $response = $this->handle($path);

            self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), $path);
            self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'), $path);
            self::assertSame('DENY', $response->headers->get('X-Frame-Options'), $path);
        }
    }

    public function testTheJsonApiIsNeverStored(): void
    {
        $response = $this->handle('/web/connections');

        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testThePageCarriesAStrictContentSecurityPolicy(): void
    {
        $policy = (string) $this->handle('/app/connections')->headers->get('Content-Security-Policy');

        self::assertStringContainsString("default-src 'self'", $policy);
        self::assertStringContainsString("script-src 'self'", $policy);
        self::assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $policy);
        self::assertStringNotContainsString('unsafe-eval', $policy);
        self::assertStringContainsString("connect-src 'self'", $policy);
        self::assertStringContainsString("frame-ancestors 'none'", $policy);
        self::assertStringContainsString("object-src 'none'", $policy);
        self::assertStringContainsString("base-uri 'none'", $policy);
        self::assertStringContainsString("form-action 'self'", $policy);
    }

    public function testTheJsonApiNeedsNoPolicy(): void
    {
        self::assertFalse($this->handle('/web/me')->headers->has('Content-Security-Policy'));
    }

    public function testSubRequestsAreLeftAlone(): void
    {
        $response = $this->handle('/web/me', HttpKernelInterface::SUB_REQUEST);

        self::assertFalse($response->headers->has('X-Content-Type-Options'));
    }

    public function testHstsIsSentOnlyOverHttps(): void
    {
        $plain = $this->handle('/app/');
        self::assertFalse($plain->headers->has('Strict-Transport-Security'));

        $response = new Response('x');
        $event = new ResponseEvent(self::createStub(HttpKernelInterface::class), Request::create('https://terril.example/app/'), HttpKernelInterface::MAIN_REQUEST, $response);
        (new SecurityHeadersListener())($event);
        self::assertStringContainsString('max-age=', (string) $response->headers->get('Strict-Transport-Security'));
    }
}

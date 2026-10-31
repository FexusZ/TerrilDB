<?php

declare(strict_types=1);

namespace App\Tests\Http\Web\Session;

use App\Http\Web\Session\UnlockCookieListener;
use App\Http\Web\Session\WrappedSessionKeyVault;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class UnlockCookieListenerTest extends TestCase
{
    private function dispatch(Request $request, Response $response, int $type = HttpKernelInterface::MAIN_REQUEST): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);

        (new UnlockCookieListener())(new ResponseEvent($kernel, $request, $type, $response));
    }

    public function testQueuedCookieIsSetOnTheResponse(): void
    {
        $request = new Request();
        $request->attributes->set(
            WrappedSessionKeyVault::COOKIE_ATTRIBUTE,
            Cookie::create(WrappedSessionKeyVault::COOKIE_NAME, 'secret'),
        );
        $response = new Response();

        $this->dispatch($request, $response);

        $cookies = $response->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertSame(WrappedSessionKeyVault::COOKIE_NAME, $cookies[0]->getName());
        self::assertSame('secret', $cookies[0]->getValue());
    }

    public function testNothingQueuedLeavesTheResponseUntouched(): void
    {
        $response = new Response();

        $this->dispatch(new Request(), $response);

        self::assertSame([], $response->headers->getCookies());
    }

    public function testSubRequestsDoNotSetTheCookie(): void
    {
        $request = new Request();
        $request->attributes->set(
            WrappedSessionKeyVault::COOKIE_ATTRIBUTE,
            Cookie::create(WrappedSessionKeyVault::COOKIE_NAME, 'secret'),
        );
        $response = new Response();

        $this->dispatch($request, $response, HttpKernelInterface::SUB_REQUEST);

        self::assertSame([], $response->headers->getCookies());
    }

    public function testForeignAttributeValueIsIgnored(): void
    {
        $request = new Request();
        $request->attributes->set(WrappedSessionKeyVault::COOKIE_ATTRIBUTE, 'not a cookie');
        $response = new Response();

        $this->dispatch($request, $response);

        self::assertSame([], $response->headers->getCookies());
    }
}

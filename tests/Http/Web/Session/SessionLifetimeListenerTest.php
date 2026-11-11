<?php

declare(strict_types=1);

namespace App\Tests\Http\Web\Session;

use App\Http\Web\Session\SessionLifetimeListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SessionLifetimeListenerTest extends TestCase
{
    private MockClock $clock;
    private Session $session;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-01-01 10:00:00');
        $this->session = new Session(new MockArraySessionStorage());
    }

    private function request(int $idle, int $max, int $type = HttpKernelInterface::MAIN_REQUEST): void
    {
        $request = Request::create('/web/me');
        $request->setSession($this->session);
        $request->cookies->set($this->session->getName(), $this->session->getId());

        (new SessionLifetimeListener($this->clock, $idle, $max))->onRequest(new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $type));
    }

    private function openSession(): void
    {
        $this->session->start();
        $this->session->set('user', 'x');
        $this->session->set(SessionLifetimeListener::OPENED_AT, $this->clock->now()->getTimestamp());
        $this->session->set(SessionLifetimeListener::LAST_SEEN, $this->clock->now()->getTimestamp());
    }

    public function testNoLimitsKeepsTheSessionForever(): void
    {
        $this->openSession();
        $this->clock->modify('+400 days');
        $this->request(0, 0);

        self::assertSame('x', $this->session->get('user'));
    }

    public function testIdleSessionIsInvalidated(): void
    {
        $this->openSession();
        $this->clock->modify('+1801 seconds');
        $this->request(1800, 0);

        self::assertNull($this->session->get('user'));
    }

    public function testActivityExtendsTheIdleWindow(): void
    {
        $this->openSession();
        $this->clock->modify('+1000 seconds');
        $this->request(1800, 0);
        $this->clock->modify('+1000 seconds');
        $this->request(1800, 0);

        self::assertSame('x', $this->session->get('user'));
    }

    public function testSessionIsInvalidatedAfterMaximumDurationEvenIfActive(): void
    {
        $this->openSession();
        for ($i = 0; $i < 4; ++$i) {
            $this->clock->modify('+1000 seconds');
            $this->request(1800, 3500);
        }

        self::assertNull($this->session->get('user'));
    }

    public function testSubRequestsAndRequestsWithoutSessionAreIgnored(): void
    {
        $this->openSession();
        $this->clock->modify('+5000 seconds');
        $this->request(10, 10, HttpKernelInterface::SUB_REQUEST);

        self::assertSame('x', $this->session->get('user'));
    }

    public function testSessionWithoutTimestampsIsStampedNotInvalidated(): void
    {
        $this->session->start();
        $this->session->set('user', 'x');
        $this->request(1800, 3600);

        self::assertSame('x', $this->session->get('user'));
        self::assertNotNull($this->session->get(SessionLifetimeListener::OPENED_AT));
    }
}

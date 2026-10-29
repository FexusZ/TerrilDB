<?php

declare(strict_types=1);

namespace App\Tests\Http\Web\Session;

use App\Crypto\SessionKeyVault;
use App\Http\Web\Session\UnlockCookieListener;
use App\Http\Web\Session\WrappedSessionKeyVault;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class SessionWiringTest extends KernelTestCase
{
    public function testTheVaultContractIsServedByTheWrappedVault(): void
    {
        self::bootKernel();

        self::assertInstanceOf(WrappedSessionKeyVault::class, self::getContainer()->get(SessionKeyVault::class));
    }

    public function testTheUnlockCookieIsSetOnEveryResponse(): void
    {
        self::bootKernel();
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $listeners = array_map(
            static fn (mixed $listener): mixed => \is_array($listener) ? $listener[0] : $listener,
            $dispatcher->getListeners(KernelEvents::RESPONSE),
        );

        self::assertContains(UnlockCookieListener::class, array_map(
            static fn (mixed $listener): ?string => \is_object($listener) ? $listener::class : null,
            $listeners,
        ));
    }

    public function testTheSessionCookieIsHardened(): void
    {
        self::bootKernel();

        /** @var array<string, mixed> $options */
        $options = self::getContainer()->getParameter('session.storage.options');

        self::assertSame('TERRILDB_SESSION', $options['name']);
        self::assertTrue($options['cookie_httponly']);
        self::assertSame('lax', $options['cookie_samesite']);
        self::assertSame('auto', $options['cookie_secure']);
    }
}

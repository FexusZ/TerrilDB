<?php

declare(strict_types=1);

namespace App\Http\Web\Session;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Pose sur la réponse le cookie de déverrouillage que WrappedSessionKeyVault a mis en attente.
 */
#[AsEventListener]
final class UnlockCookieListener
{
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $cookie = $event->getRequest()->attributes->get(WrappedSessionKeyVault::COOKIE_ATTRIBUTE);
        if ($cookie instanceof Cookie) {
            $event->getResponse()->headers->setCookie($cookie);
        }
    }
}

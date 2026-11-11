<?php

declare(strict_types=1);

namespace App\Http\Web\Session;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Borne la vie d'une session : inactivité (depuis la dernière requête) et durée maximale (depuis la connexion). Une limite à 0
 * est sans effet, ce qui garde le comportement d'avant (la session vit tant que PHP ne la purge pas). Invalider la session
 * efface aussi l'enveloppe de la clé de chiffrement qu'elle porte : il faut se reconnecter.
 */
final readonly class SessionLifetimeListener
{
    public const OPENED_AT = '_terrildb.opened_at';
    public const LAST_SEEN = '_terrildb.last_seen';

    public function __construct(
        private ClockInterface $clock,
        #[Autowire('%env(int:TERRILDB_SESSION_IDLE_SECONDS)%')] private int $idleSeconds,
        #[Autowire('%env(int:TERRILDB_SESSION_MAX_SECONDS)%')] private int $maxSeconds,
    ) {
    }

    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onLogin(LoginSuccessEvent $event): void
    {
        $session = $event->getRequest()->hasSession() ? $event->getRequest()->getSession() : null;
        $now = $this->clock->now()->getTimestamp();
        $session?->set(self::OPENED_AT, $now);
        $session?->set(self::LAST_SEEN, $now);
    }

    #[AsEventListener(event: 'kernel.request', priority: 16)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasPreviousSession()) {
            return;
        }

        $session = $request->getSession();
        $now = $this->clock->now()->getTimestamp();
        $openedAt = $session->get(self::OPENED_AT);
        $lastSeen = $session->get(self::LAST_SEEN);

        if (\is_int($openedAt) && \is_int($lastSeen)) {
            $idle = $this->idleSeconds > 0 && $now - $lastSeen > $this->idleSeconds;
            $expired = $this->maxSeconds > 0 && $now - $openedAt > $this->maxSeconds;
            if ($idle || $expired) {
                $session->invalidate();

                return;
            }
        }

        $session->set(self::OPENED_AT, \is_int($openedAt) ? $openedAt : $now);
        $session->set(self::LAST_SEEN, $now);
    }
}

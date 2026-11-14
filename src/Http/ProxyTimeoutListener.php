<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Json\RequestTimeLimit;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Le reverse proxy de production annonce son délai réel dans `X-TerrilDB-Proxy-Timeout` (docker/prod/nginx.conf.template ;
 * nginx remplace toujours cet en-tête, un client ne peut pas le choisir). S'il diffère de TERRILDB_PROXY_TIMEOUT, la limite de
 * durée des requêtes ne correspond plus à ce que le proxy tolère : on le dit dans le journal, une fois par heure. Sans l'en-tête (développement, accès direct) il n'y a rien à contrôler.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 32)]
final readonly class ProxyTimeoutListener
{
    public const HEADER = 'X-TerrilDB-Proxy-Timeout';
    private const REMINDER_SECONDS = 3600;
    /** Une seule clé : l'en-tête, atteint en direct, ne doit pas pouvoir remplir le cache avec des valeurs différentes. */
    private const REMINDER_KEY = 'proxy_timeout_mismatch';

    public function __construct(
        private RequestTimeLimit $limit,
        private LoggerInterface $logger,
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $announced = $event->getRequest()->headers->get(self::HEADER);
        if (null === $announced || 1 !== preg_match('/^[0-9]{1,6}$/D', $announced)) {
            return;
        }
        $seen = (int) $announced;
        $configured = $this->limit->proxySeconds();
        if ($seen === $configured) {
            return;
        }

        $item = $this->cache->getItem(self::REMINDER_KEY);
        if ($item->isHit()) {
            return;
        }
        $this->cache->save($item->set(true)->expiresAfter(self::REMINDER_SECONDS));
        $this->logger->error(\sprintf(
            'The reverse proxy cuts answers after %d s but TERRILDB_PROXY_TIMEOUT is %d s: query durations are limited for the wrong delay. Align them with `sh docker/prod/sync-nginx.sh` (or fix deploy.env).',
            $seen,
            $configured,
        ));
    }
}

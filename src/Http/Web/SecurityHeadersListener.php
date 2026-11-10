<?php

declare(strict_types=1);

namespace App\Http\Web;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * En-têtes de protection de toutes les réponses. La page n'accepte que ses propres scripts (aucun script en
 * ligne, aucun eval) ; seuls les styles en ligne sont admis, CodeMirror en injecte à l'exécution. L'API JSON
 * n'est jamais mise en cache : ses réponses contiennent des schémas et des résultats de requêtes.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final class SecurityHeadersListener
{
    public const CONTENT_SECURITY_POLICY = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'";

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $headers = $event->getResponse()->headers;
        $path = $request->getPathInfo();

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('X-Frame-Options', 'DENY');

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=15552000');
        }
        if (str_starts_with($path, '/web/') || str_starts_with($path, '/api/') || '/mcp' === $path) {
            $headers->set('Cache-Control', 'no-store');
        }
        if (str_starts_with($path, '/app/') || '/app' === $path) {
            $headers->set('Content-Security-Policy', self::CONTENT_SECURITY_POLICY);
        }
    }
}

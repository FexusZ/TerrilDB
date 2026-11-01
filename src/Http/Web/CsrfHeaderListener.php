<?php

declare(strict_types=1);

namespace App\Http\Web;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Protection CSRF de l'API web : toute requête qui modifie quelque chose doit porter l'en-tête
 * `X-TerrilDB-Request`. Un formulaire ou une image venus d'un autre site ne peuvent pas l'ajouter, et un
 * appel JavaScript inter-sites qui l'ajouterait déclencherait une pré-vérification CORS que le serveur
 * n'autorise pas. S'ajoute à SameSite=Lax du cookie de session.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 16)]
final class CsrfHeaderListener
{
    public const HEADER = 'X-TerrilDB-Request';

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/web/') || $request->isMethodSafe()) {
            return;
        }

        if ('1' !== $request->headers->get(self::HEADER)) {
            $event->setResponse(new JsonResponse(['error' => 'csrf'], Response::HTTP_FORBIDDEN));
        }
    }
}

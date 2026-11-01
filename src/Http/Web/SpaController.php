<?php

declare(strict_types=1);

namespace App\Http\Web;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sert l'application Vue sous /app/. Les fichiers compilés (/app/assets/…) sont servis tels quels par le serveur
 * web ; toute autre adresse /app/… reçoit l'index (compilé sous le nom spa.html, voir vite.config.ts), et c'est le
 * routeur du navigateur qui prend le relais.
 */
final readonly class SpaController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/public/app/spa.html')] private string $indexFile,
    ) {
    }

    #[Route('/', methods: ['GET'])]
    public function root(): Response
    {
        return new RedirectResponse('/app/');
    }

    #[Route('/app/{path}', requirements: ['path' => '.*'], defaults: ['path' => ''], methods: ['GET'], priority: -10)]
    public function app(): Response
    {
        $index = is_file($this->indexFile) ? file_get_contents($this->indexFile) : false;
        if (false === $index) {
            return new Response(
                "L'interface n'est pas compilée. Lancez : docker compose run --rm node npm run build\n",
                Response::HTTP_SERVICE_UNAVAILABLE,
                ['Content-Type' => 'text/plain; charset=utf-8'],
            );
        }

        // L'index change à chaque compilation (noms de fichiers à empreinte) : il ne doit jamais rester en cache.
        return new Response($index, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-cache']);
    }
}

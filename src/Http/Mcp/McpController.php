<?php

declare(strict_types=1);

namespace App\Http\Mcp;

use App\Http\Api\ApiRequestAccess;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Point d'entrée MCP (transport HTTP « streamable »). L'authentification est faite avant : le pare-feu exige un jeton
 * d'API valide, comme pour l'API REST, et chaque requête est donc rejouée sous l'identité de ce jeton, session MCP
 * ou non. La session MCP elle-même est liée au jeton qui l'a ouverte.
 *
 * Seuls POST (messages) et DELETE (fin de session) sont servis : pas de flux serveur ouvert (GET), que PHP ne peut pas
 * tenir entre deux requêtes. Le client doit alors se contenter des réponses directes, ce que le protocole prévoit.
 */
final readonly class McpController
{
    private const SESSION_HEADER = 'Mcp-Session-Id';

    public function __construct(
        private McpServerFactory $servers,
        private McpSessionBinding $sessions,
        private ApiRequestAccess $access,
        #[Autowire('%env(TERRILDB_ALLOWED_HOSTS)%')] private string $allowedHosts,
    ) {
    }

    #[Route('/mcp', methods: ['POST', 'DELETE'])]
    public function handle(Request $request): Response
    {
        $tokenId = $this->access->get()->token->getId();
        $presented = $request->headers->get(self::SESSION_HEADER);
        if (null !== $presented && !$this->sessions->belongsTo($presented, $tokenId)) {
            return new JsonResponse(['error' => 'session_not_found'], Response::HTTP_NOT_FOUND);
        }

        $factory = new Psr17Factory();
        $hosts = array_values(array_filter(array_map('trim', explode(',', $this->allowedHosts)), static fn (string $host): bool => '' !== $host));
        $transport = new StreamableHttpTransport(
            (new PsrHttpFactory($factory, $factory, $factory, $factory))->createRequest($request),
            $factory,
            $factory,
            null,
            [new DnsRebindingProtectionMiddleware($hosts)],
        );

        $psrResponse = $this->servers->create()->run($transport);
        $response = (new HttpFoundationFactory())->createResponse($psrResponse);

        $opened = $response->headers->get(self::SESSION_HEADER);
        if (null === $presented && null !== $opened && $response->isSuccessful()) {
            $this->sessions->bind($opened, $tokenId);
        }
        if (null !== $presented && 'DELETE' === $request->getMethod() && $response->isSuccessful()) {
            $this->sessions->forget($presented);
        }

        return $response;
    }

    #[Route('/mcp', methods: ['GET'])]
    public function noStream(): Response
    {
        return new JsonResponse(['error' => 'method_not_allowed'], Response::HTTP_METHOD_NOT_ALLOWED, ['Allow' => 'POST, DELETE']);
    }
}

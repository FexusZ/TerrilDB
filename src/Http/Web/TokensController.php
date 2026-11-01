<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\ApiToken\ApiTokenService;
use App\Connection\Exception\ConnectionNotFoundException;
use App\Http\Json\JsonInput;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Jetons d'accès à l'API (lecture seule). Le texte du jeton n'est rendu qu'à la création.
 */
final readonly class TokensController
{
    private const DEFAULT_LIFETIME_DAYS = 30;

    public function __construct(
        private ApiTokenService $tokens,
        private WebSession $session,
    ) {
    }

    #[Route('/web/tokens', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return new JsonResponse(['tokens' => array_map(TokenPresenter::present(...), $this->tokens->list($this->session->user()))]);
    }

    #[Route('/web/tokens', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $input = JsonInput::fromRequest($request);
        $name = $input->string('name');
        $connections = array_map(
            static fn (string $id): Uuid => Uuid::isValid($id) ? Uuid::fromString($id) : throw ConnectionNotFoundException::create(),
            $input->stringList('connectionIds'),
        );
        $lifetime = $input->optionalInt('lifetimeDays', self::DEFAULT_LIFETIME_DAYS) ?? self::DEFAULT_LIFETIME_DAYS;

        $issued = $this->tokens->issue($this->session->user(), $this->session->key(), $name, $connections, $lifetime);

        return new JsonResponse(['token' => $issued->token, 'tokenInfo' => TokenPresenter::present($issued->entity)], Response::HTTP_CREATED);
    }

    #[Route('/web/tokens/{id}', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['DELETE'])]
    public function revoke(string $id): Response
    {
        $this->tokens->revoke($this->session->user(), Uuid::fromString($id));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}

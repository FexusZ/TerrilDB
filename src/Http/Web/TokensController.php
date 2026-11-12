<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\ApiToken\ApiTokenService;
use App\ApiToken\TokenPermissions;
use App\Audit\AuditAction;
use App\Audit\AuditLog;
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
        private AuditLog $audit,
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
        $all = $input->bool('allConnections', false);
        $connections = $all ? [] : array_map(
            static fn (string $id): Uuid => Uuid::isValid($id) ? Uuid::fromString($id) : throw ConnectionNotFoundException::create(),
            $input->stringList('connectionIds'),
        );
        $never = $input->bool('neverExpires', false);
        $permissions = new TokenPermissions($input->bool('writeData', false), $input->bool('writeSchema', false), $input->bool('createConnections', false), $input->bool('singleStep', false));
        $lifetime = $never ? null : ($input->optionalInt('lifetimeDays', self::DEFAULT_LIFETIME_DAYS) ?? self::DEFAULT_LIFETIME_DAYS);

        $issued = $this->tokens->issue($this->session->user(), $this->session->key(), $name, $connections, $lifetime, $all, $permissions);
        $this->audit->record($this->session->auditContext(), AuditAction::TokenCreated, subject: $issued->entity->getId(), details: ['name' => $issued->entity->getName(), 'connections' => \count($issued->entity->getConnectionIds()), 'lifetimeDays' => $lifetime] + array_filter(['writeData' => $permissions->writeData, 'writeSchema' => $permissions->writeSchema, 'createConnections' => $permissions->createConnections, 'singleStep' => $permissions->singleStep]));

        return new JsonResponse(['token' => $issued->token, 'tokenInfo' => TokenPresenter::present($issued->entity)], Response::HTTP_CREATED);
    }

    #[Route('/web/tokens/{id}/refresh', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function refresh(string $id): JsonResponse
    {
        $user = $this->session->user();
        $token = $this->tokens->refresh($user, Uuid::fromString($id));
        $this->audit->record($this->session->auditContext($user), AuditAction::TokenRefreshed, subject: $token->getId(), details: ['name' => $token->getName(), 'connections' => \count($token->getConnectionIds())]);

        return new JsonResponse(['tokenInfo' => TokenPresenter::present($token)]);
    }

    #[Route('/web/tokens/{id}', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['DELETE'])]
    public function revoke(string $id): Response
    {
        $user = $this->session->user();
        $uuid = Uuid::fromString($id);
        $this->tokens->revoke($user, $uuid);
        $this->audit->record($this->session->auditContext($user), AuditAction::TokenRevoked, subject: $uuid);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}

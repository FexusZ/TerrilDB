<?php

declare(strict_types=1);

namespace App\Audit;

use App\ApiToken\ApiTokenAccess;
use App\Entity\User;
use App\Http\Api\ApiRequestAccess;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Le contexte d'audit de la requête en cours : la porte (interface, API ou MCP), le jeton éventuel et l'adresse du client.
 * Pour les services du cœur qui n'ont pas la requête sous la main.
 */
final readonly class RequestAuditContext
{
    public function __construct(private RequestStack $requests)
    {
    }

    public function for(User $user): AuditContext
    {
        $request = $this->requests->getMainRequest();
        $access = $request?->attributes->get(ApiRequestAccess::ATTRIBUTE);
        if ($request instanceof Request && $access instanceof ApiTokenAccess) {
            $actor = str_starts_with($request->getPathInfo(), '/mcp') ? AuditActor::Mcp : AuditActor::Api;

            return new AuditContext($actor, $user->getId(), $access->token->getId(), $request->getClientIp());
        }

        return new AuditContext(AuditActor::Web, $user->getId(), null, $request?->getClientIp());
    }
}

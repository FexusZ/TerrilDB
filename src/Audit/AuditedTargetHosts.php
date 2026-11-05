<?php

declare(strict_types=1);

namespace App\Audit;

use App\ApiToken\ApiTokenAccess;
use App\Connection\Exception\ForbiddenTargetHostException;
use App\Connection\TargetHostPolicy;
use App\Core\Connection\ConnectionParameters;
use App\Entity\User;
use App\Http\Api\ApiRequestAccess;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * La règle d'adresses (TargetHostPolicy) devant toutes les portes, qui garde la trace d'un refus : essayer de viser le
 * serveur lui-même ressemble à un sondage du réseau, et le journal doit le montrer, avec la porte (interface, API ou MCP)
 * et le jeton concernés. Seuls l'adresse saisie et le port sont journalisés, jamais l'identifiant ni le mot de passe.
 */
final readonly class AuditedTargetHosts
{
    private const MAX_HOST_LENGTH = 255;

    public function __construct(
        private TargetHostPolicy $policy,
        private AuditLog $audit,
        private RequestStack $requests,
    ) {
    }

    /**
     * @throws ForbiddenTargetHostException
     */
    public function assertAllowed(User $user, ConnectionParameters $parameters): void
    {
        $this->check($user, $parameters);
    }

    /**
     * Les mêmes paramètres, épinglés sur l'adresse qui vient d'être contrôlée : à donner au driver à la place des
     * paramètres d'origine. Sans réponse du DNS, ils reviennent tels quels (la connexion échouera d'elle-même).
     *
     * @throws ForbiddenTargetHostException
     */
    public function pinned(User $user, ConnectionParameters $parameters): ConnectionParameters
    {
        $address = $this->check($user, $parameters);

        return null === $address ? $parameters : $parameters->withAddress($address);
    }

    private function check(User $user, ConnectionParameters $parameters): ?string
    {
        try {
            return $this->policy->pinnedAddress($user, $parameters);
        } catch (ForbiddenTargetHostException $refused) {
            $this->audit->record(
                $this->context($user),
                AuditAction::TargetForbidden,
                AuditOutcome::Refused,
                details: ['host' => mb_substr($parameters->host, 0, self::MAX_HOST_LENGTH), 'port' => $parameters->port],
            );

            throw $refused;
        }
    }

    private function context(User $user): AuditContext
    {
        $request = $this->requests->getMainRequest();
        $access = $request?->attributes->get(ApiRequestAccess::ATTRIBUTE);
        if ($request instanceof \Symfony\Component\HttpFoundation\Request && $access instanceof ApiTokenAccess) {
            $actor = str_starts_with($request->getPathInfo(), '/mcp') ? AuditActor::Mcp : AuditActor::Api;

            return new AuditContext($actor, $user->getId(), $access->token->getId(), $request->getClientIp());
        }

        return new AuditContext(AuditActor::Web, $user->getId(), null, $request?->getClientIp());
    }
}

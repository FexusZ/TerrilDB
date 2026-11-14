<?php

declare(strict_types=1);

namespace App\Audit;

use App\Connection\Exception\ForbiddenTargetHostException;
use App\Connection\TargetHostPolicy;
use App\Core\Connection\ConnectionParameters;
use App\Entity\User;
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
            // Avec un rebond, on note l'adresse refusée (le bastion, ou la cible derrière lui) et le rebond, jamais l'identifiant ni la clé.
            [$host, $port] = null === $parameters->transport || $refused->behindJump ? [$parameters->host, $parameters->port] : $parameters->firstHop();
            $details = ['host' => mb_substr($host, 0, self::MAX_HOST_LENGTH), 'port' => $port];
            if (null !== $parameters->transport) {
                $details['via'] = 'jump';
            }
            $this->audit->record((new RequestAuditContext($this->requests))->for($user), AuditAction::TargetForbidden, AuditOutcome::Refused, details: $details);

            throw $refused;
        }
    }
}

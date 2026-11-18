<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Audit\AuditedTargetHosts;
use App\Audit\AuditedTunnels;
use App\Http\Json\JsonInput;
use App\Http\Json\TooManyAttemptsException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Première connexion à un bastion : le serveur lit l'empreinte de sa clé d'hôte (par le conteneur du tunnel) et la montre ;
 * c'est l'utilisateur qui la confirme en l'enregistrant avec la connexion. Plafonné par compte (20 par 10 minutes). Rien n'est enregistré ici, et rien n'est accepté
 * en silence : sans empreinte confirmée, le tunnel refuse de s'ouvrir.
 */
final readonly class JumpHostKeyController
{
    public function __construct(
        private AuditedTargetHosts $hosts,
        private AuditedTunnels $tunnels,
        private WebSession $session,
        #[Autowire(service: 'limiter.host_key_read')] private RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/web/connections/host-key', methods: ['POST'])]
    public function read(Request $request): JsonResponse
    {
        $input = JsonInput::fromRequest($request);
        $host = $input->string('host');
        $port = $input->optionalInt('port') ?? 22;
        $user = $this->session->user();

        // Chaque lecture compte, refusée ou non : c'est le relais qui sort vers le bastion, et le bastion pénalise son adresse.
        $state = $this->limiter->create($user->getId()->toRfc4122())->consume();
        if (!$state->isAccepted()) {
            throw new TooManyAttemptsException(max(1, $state->getRetryAfter()->getTimestamp() - time()));
        }
        $this->hosts->assertBastion($user, $host, $port);
        $key = $this->tunnels->hostKey($this->session->auditContext($user), $host, $port);

        return new JsonResponse(['fingerprint' => $key->fingerprint, 'keyType' => $key->type]);
    }
}

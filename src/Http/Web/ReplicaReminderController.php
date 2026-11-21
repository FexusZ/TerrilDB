<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Connection\ReplicaReminder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Le rappel hebdomadaire d'un réplica Vitess (#40). Mince : tout est dans `ReplicaReminder`. Toujours cloisonné par propriétaire
 * (une connexion d'autrui = 404).
 */
final readonly class ReplicaReminderController
{
    private const ID = ['id' => '[0-9a-fA-F-]{36}'];

    public function __construct(
        private ReplicaReminder $reminder,
        private WebSession $session,
    ) {
    }

    /**
     * `available` : un réplica est apparu, l'interface propose d'activer la lecture (rien n'est activé d'office).
     */
    #[Route('/web/connections/{id}/replica', requirements: self::ID, methods: ['GET'])]
    public function check(string $id): JsonResponse
    {
        return new JsonResponse(['available' => $this->reminder->check($this->session->user(), $this->session->key(), Uuid::fromString($id))]);
    }

    #[Route('/web/connections/{id}/replica/enable', requirements: self::ID, methods: ['POST'])]
    public function enable(string $id): Response
    {
        $this->reminder->enable($this->session->user(), $this->session->key(), Uuid::fromString($id));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/web/connections/{id}/replica/later', requirements: self::ID, methods: ['POST'])]
    public function later(string $id): Response
    {
        $this->reminder->later($this->session->user(), Uuid::fromString($id));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}

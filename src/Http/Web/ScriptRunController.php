<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Http\Json\JsonInput;
use App\Http\Json\ScriptRunPresenter;
use App\ScriptRun\ScriptRunAccess;
use App\ScriptRun\ScriptRunStarter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Un script SQL exécuté EN ARRIÈRE-PLAN (#169) : on le lance (202, l'exécution est confiée au worker), on suit son avancement
 * instruction par instruction, on peut demander l'arrêt entre deux instructions. Aucun KILL : l'instruction en cours va à son terme.
 * Même jugement que `/script/execute` (refus, une confirmation, jeu à blanc) ; les droits d'une session web sont ceux de la console.
 */
final readonly class ScriptRunController
{
    /** Au-delà de la durée d'une requête : c'est le but, l'exécution ne dépend plus du proxy. */
    private const DEFAULT_TIMEOUT_MS = 600_000;

    public function __construct(
        private ScriptRunStarter $starter,
        private ScriptRunAccess $access,
        private WebSession $session,
    ) {
    }

    #[Route('/web/connections/{id}/script/run', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function start(string $id, Request $request): JsonResponse
    {
        $input = JsonInput::fromRequest($request);
        $run = $this->starter->start(
            $this->session->user(),
            $this->session->key(),
            $this->session->auditContext(),
            Uuid::fromString($id),
            $input->string('script'),
            $input->bool('confirmed', false),
            $input->bool('dryRun', false),
            $input->optionalInt('timeoutMs') ?? self::DEFAULT_TIMEOUT_MS,
            $input->optionalString('database'),
            $input->optionalString('schema'),
            lenient: true,
        );

        return new JsonResponse(['run' => ScriptRunPresenter::run($run)], Response::HTTP_ACCEPTED);
    }

    #[Route('/web/script-runs/{id}', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        return new JsonResponse(['run' => ScriptRunPresenter::run($this->access->read($this->session->user(), Uuid::fromString($id)))]);
    }

    #[Route('/web/script-runs/{id}/cancel', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function cancel(string $id): JsonResponse
    {
        return new JsonResponse(['run' => ScriptRunPresenter::run($this->access->cancel($this->session->user(), Uuid::fromString($id)))]);
    }
}

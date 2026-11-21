<?php

declare(strict_types=1);

namespace App\Http\Api;

use App\Audit\AuditActor;
use App\Http\Json\JsonInput;
use App\Http\Json\ScriptRunPresenter;
use App\ScriptRun\TokenScriptRuns;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Un script lancé EN ARRIÈRE-PLAN par un jeton (#169) : 202 + un identifiant, suivi par scrutation, arrêt entre deux instructions (jamais de KILL).
 * Mêmes droits et même ticket de confirmation que `script/execute` ; seul le jeton lanceur suit ou arrête l'exécution (404 pour tout autre).
 */
final readonly class ApiScriptRunController
{
    public function __construct(private ApiRequestAccess $request, private TokenScriptRuns $runs)
    {
    }

    #[Route('/api/v1/connections/{id}/script/run', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function start(string $id, Request $request): JsonResponse
    {
        $input = JsonInput::fromRequest($request);
        $run = $this->runs->launch(
            $this->request->get(),
            $this->request->auditContext(AuditActor::Api),
            Uuid::fromString($id),
            $input->string('script'),
            $input->optionalString('confirmationToken'),
            $input->bool('confirmed', false),
            $input->bool('dryRun', false),
            $input->optionalInt('timeoutMs'),
        );

        return new JsonResponse(['run' => ScriptRunPresenter::run($run)], Response::HTTP_ACCEPTED);
    }

    #[Route('/api/v1/script-runs/{id}', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        return new JsonResponse(['run' => ScriptRunPresenter::run($this->runs->read($this->request->get(), Uuid::fromString($id)))]);
    }

    #[Route('/api/v1/script-runs/{id}/cancel', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function cancel(string $id): JsonResponse
    {
        return new JsonResponse(['run' => ScriptRunPresenter::run($this->runs->cancel($this->request->get(), Uuid::fromString($id)))]);
    }
}

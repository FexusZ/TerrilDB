<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Audit\AuditedDiagnostics;
use App\Core\Driver\TargetConnection;
use App\Http\Json\DiagnosticsPresenter;
use App\Http\Json\DigestQueryParam;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Supervision du serveur d'une connexion. Ces lectures ne passent jamais par la console ni par le garde : ce sont des requêtes
 * de catalogue fixes, écrites par le serveur, en lecture seule.
 */
final readonly class DiagnosticsController
{
    private const ID = ['id' => '[0-9a-fA-F-]{36}'];

    public function __construct(
        private TargetAccess $targets,
        private AuditedDiagnostics $diagnostics,
        private WebSession $session,
    ) {
    }

    #[Route('/web/connections/{id}/diagnostics', requirements: self::ID, methods: ['GET'])]
    public function capabilities(string $id, Request $request): JsonResponse
    {
        $capabilities = $this->targets->withSaved(Uuid::fromString($id), static fn (TargetConnection $target): array => $target->diagnostics()->capabilities(), DatabaseParam::fromQuery($request), DatabaseParam::schemaFromQuery($request));

        return new JsonResponse(['capabilities' => DiagnosticsPresenter::capabilities($capabilities)]);
    }

    #[Route('/web/connections/{id}/diagnostics/sessions', requirements: self::ID, methods: ['GET'])]
    public function sessions(string $id, Request $request): JsonResponse
    {
        $uuid = Uuid::fromString($id);
        $report = $this->targets->withSaved($uuid, fn (TargetConnection $target) => $this->diagnostics->sessions($this->session->auditContext(), $uuid, $target), DatabaseParam::fromQuery($request), DatabaseParam::schemaFromQuery($request));

        return new JsonResponse(DiagnosticsPresenter::sessions($report));
    }

    #[Route('/web/connections/{id}/diagnostics/locks', requirements: self::ID, methods: ['GET'])]
    public function locks(string $id, Request $request): JsonResponse
    {
        $uuid = Uuid::fromString($id);
        $report = $this->targets->withSaved($uuid, fn (TargetConnection $target) => $this->diagnostics->locks($this->session->auditContext(), $uuid, $target), DatabaseParam::fromQuery($request), DatabaseParam::schemaFromQuery($request));

        return new JsonResponse(DiagnosticsPresenter::locks($report));
    }

    #[Route('/web/connections/{id}/diagnostics/queries', requirements: self::ID, methods: ['GET'])]
    public function queries(string $id, Request $request): JsonResponse
    {
        $uuid = Uuid::fromString($id);
        $query = DigestQueryParam::fromQuery($request);
        $report = $this->targets->withSaved($uuid, fn (TargetConnection $target) => $this->diagnostics->statements($this->session->auditContext(), $uuid, $target, $query), DatabaseParam::fromQuery($request), DatabaseParam::schemaFromQuery($request));

        return new JsonResponse(DiagnosticsPresenter::statements($report));
    }
}

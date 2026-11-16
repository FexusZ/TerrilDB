<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Audit\AuditedDiagnostics;
use App\Core\Diagnostics\Masking;
use App\Core\Driver\TargetConnection;
use App\Http\Json\DiagnosticsPresenter;
use App\Http\Json\DigestQueryParam;
use App\Http\Json\HealthInterval;
use App\Http\Json\VariableQueryParser;
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
        private Masking $masking,
    ) {
    }

    #[Route('/web/connections/{id}/diagnostics', requirements: self::ID, methods: ['GET'])]
    public function capabilities(string $id, Request $request): JsonResponse
    {
        $capabilities = $this->targets->withSaved(Uuid::fromString($id), fn (TargetConnection $target): array => $this->diagnostics->capabilities($target), DatabaseParam::fromQuery($request), DatabaseParam::schemaFromQuery($request));

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

    #[Route('/web/connections/{id}/diagnostics/variables', requirements: self::ID, methods: ['GET'])]
    public function variables(string $id, Request $request): JsonResponse
    {
        $uuid = Uuid::fromString($id);
        $query = VariableQueryParser::fromRequest($request);
        $report = $this->targets->withSaved($uuid, fn (TargetConnection $target) => $this->diagnostics->variables($this->session->auditContext(), $uuid, $target, $query), DatabaseParam::fromQuery($request), DatabaseParam::schemaFromQuery($request));

        return new JsonResponse(DiagnosticsPresenter::variables($report));
    }

    #[Route('/web/connections/{id}/diagnostics/accounts', requirements: self::ID, methods: ['GET'])]
    public function accounts(string $id, Request $request): JsonResponse
    {
        $uuid = Uuid::fromString($id);
        $report = $this->targets->withSaved($uuid, fn (TargetConnection $target) => $this->diagnostics->accounts($this->session->auditContext(), $uuid, $target), DatabaseParam::fromQuery($request), DatabaseParam::schemaFromQuery($request));

        return new JsonResponse(DiagnosticsPresenter::accounts($report));
    }

    #[Route('/web/connections/{id}/diagnostics/encryption', requirements: self::ID, methods: ['GET'])]
    public function encryption(string $id, Request $request): JsonResponse
    {
        $uuid = Uuid::fromString($id);
        $encryption = $this->targets->withSaved($uuid, fn (TargetConnection $target) => $this->diagnostics->encryption($this->session->auditContext(), $uuid, $target), DatabaseParam::fromQuery($request), DatabaseParam::schemaFromQuery($request));

        return new JsonResponse(DiagnosticsPresenter::encryption($encryption));
    }

    #[Route('/web/connections/{id}/diagnostics/health', requirements: self::ID, methods: ['GET'])]
    public function health(string $id, Request $request): JsonResponse
    {
        $uuid = Uuid::fromString($id);
        $seconds = HealthInterval::parse($request->query->get('seconds'));
        $report = $this->targets->withSaved($uuid, fn (TargetConnection $target) => $this->diagnostics->health($this->session->auditContext(), $uuid, $target, $seconds), DatabaseParam::fromQuery($request), DatabaseParam::schemaFromQuery($request));

        return new JsonResponse(DiagnosticsPresenter::health($report));
    }

    #[Route('/web/connections/{id}/diagnostics/replication', requirements: self::ID, methods: ['GET'])]
    public function replication(string $id, Request $request): JsonResponse
    {
        $uuid = Uuid::fromString($id);
        $report = $this->targets->withSaved($uuid, fn (TargetConnection $target) => $this->diagnostics->replication($this->session->auditContext(), $uuid, $target), DatabaseParam::fromQuery($request), DatabaseParam::schemaFromQuery($request));

        return new JsonResponse(DiagnosticsPresenter::replication($report, $this->masking->enabled));
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Audit\AuditedDiagnostics;
use App\Core\Diagnostics\Exception\InvalidVariableQueryException;
use App\Core\Diagnostics\Masking;
use App\Core\Diagnostics\Vitess\TabletSnapshot;
use App\Core\Diagnostics\Vitess\VitessTablet;
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
        $capabilities = $this->targets->withSaved(Uuid::fromString($id), fn (TargetConnection $target): array => $this->diagnostics->capabilities($target), DatabaseParam::fromQuery($request), DatabaseParam::schemaFromQuery($request), DatabaseParam::tabletFromQuery($request));

        return new JsonResponse(['capabilities' => DiagnosticsPresenter::capabilities($capabilities)]);
    }

    /**
     * Les tablets que vtgate sait viser : de quoi choisir celui dont on veut lire les autres thèmes (`?tablet=<alias>`).
     */
    #[Route('/web/connections/{id}/diagnostics/tablets', requirements: self::ID, methods: ['GET'])]
    public function tablets(string $id, Request $request): JsonResponse
    {
        $uuid = Uuid::fromString($id);
        $tablets = $this->targets->withSaved($uuid, fn (TargetConnection $target) => $this->diagnostics->tablets($this->session->auditContext(), $uuid, $target), DatabaseParam::fromQuery($request), DatabaseParam::schemaFromQuery($request));

        return new JsonResponse(DiagnosticsPresenter::tablets($tablets));
    }

    /**
     * Les migrations de DDL en ligne du keyspace. Lecture seule : les actions (annuler, rejouer, terminer, lancer) sont des instructions
     * `ALTER VITESS_MIGRATION` envoyées par la console et ses confirmations.
     */
    #[Route('/web/connections/{id}/diagnostics/migrations', requirements: self::ID, methods: ['GET'])]
    public function migrations(string $id, Request $request): JsonResponse
    {
        $uuid = Uuid::fromString($id);
        $report = $this->targets->withSaved($uuid, fn (TargetConnection $target) => $this->diagnostics->migrations($this->session->auditContext(), $uuid, $target), DatabaseParam::fromQuery($request), DatabaseParam::schemaFromQuery($request));

        return new JsonResponse(DiagnosticsPresenter::migrations($report));
    }

    /**
     * Les tablets d'un keyspace côte à côte : la réplication de chacun et les variables modifiées dont la valeur diffère. Une session par
     * tablet, lecture seule.
     */
    #[Route('/web/connections/{id}/diagnostics/tablets/compare', requirements: self::ID, methods: ['GET'])]
    public function compareTablets(string $id, Request $request): JsonResponse
    {
        $uuid = Uuid::fromString($id);
        $database = DatabaseParam::fromQuery($request);
        $keyspace = null === $database ? null : explode('@', explode(':', $database, 2)[0], 2)[0];
        $comparison = $this->targets->withSaved($uuid, fn (TargetConnection $gateway) => $this->diagnostics->compareTablets(
            $this->session->auditContext(),
            $uuid,
            $gateway,
            $keyspace,
            fn (VitessTablet $tablet): TabletSnapshot => $this->targets->withSaved($uuid, static fn (TargetConnection $target): TabletSnapshot => TabletSnapshot::read($tablet, $target->diagnostics()), $database, DatabaseParam::schemaFromQuery($request), $tablet->alias),
        ), $database, DatabaseParam::schemaFromQuery($request));

        return new JsonResponse(DiagnosticsPresenter::tabletComparison($comparison));
    }

    #[Route('/web/connections/{id}/diagnostics/sessions', requirements: self::ID, methods: ['GET'])]
    public function sessions(string $id, Request $request): JsonResponse
    {
        $uuid = Uuid::fromString($id);
        $report = $this->targets->withSaved($uuid, fn (TargetConnection $target) => $this->diagnostics->sessions($this->session->auditContext(), $uuid, $target), DatabaseParam::fromQuery($request), DatabaseParam::schemaFromQuery($request), DatabaseParam::tabletFromQuery($request));

        return new JsonResponse(DiagnosticsPresenter::sessions($report));
    }

    #[Route('/web/connections/{id}/diagnostics/locks', requirements: self::ID, methods: ['GET'])]
    public function locks(string $id, Request $request): JsonResponse
    {
        $uuid = Uuid::fromString($id);
        $report = $this->targets->withSaved($uuid, fn (TargetConnection $target) => $this->diagnostics->locks($this->session->auditContext(), $uuid, $target), DatabaseParam::fromQuery($request), DatabaseParam::schemaFromQuery($request), DatabaseParam::tabletFromQuery($request));

        return new JsonResponse(DiagnosticsPresenter::locks($report));
    }

    #[Route('/web/connections/{id}/diagnostics/queries', requirements: self::ID, methods: ['GET'])]
    public function queries(string $id, Request $request): JsonResponse
    {
        $uuid = Uuid::fromString($id);
        $query = DigestQueryParam::fromQuery($request);
        $report = $this->targets->withSaved($uuid, fn (TargetConnection $target) => $this->diagnostics->statements($this->session->auditContext(), $uuid, $target, $query), DatabaseParam::fromQuery($request), DatabaseParam::schemaFromQuery($request), DatabaseParam::tabletFromQuery($request));

        return new JsonResponse(DiagnosticsPresenter::statements($report));
    }

    #[Route('/web/connections/{id}/diagnostics/variables', requirements: self::ID, methods: ['GET'])]
    public function variables(string $id, Request $request): JsonResponse
    {
        $uuid = Uuid::fromString($id);
        $query = VariableQueryParser::fromRequest($request);
        $report = $this->targets->withSaved($uuid, fn (TargetConnection $target) => $this->diagnostics->variables($this->session->auditContext(), $uuid, $target, $query), DatabaseParam::fromQuery($request), DatabaseParam::schemaFromQuery($request), DatabaseParam::tabletFromQuery($request));

        return new JsonResponse(DiagnosticsPresenter::variables($report));
    }

    #[Route('/web/connections/{id}/diagnostics/config-audit', requirements: self::ID, methods: ['GET'])]
    public function configAudit(string $id, Request $request): JsonResponse
    {
        $uuid = Uuid::fromString($id);
        $report = $this->targets->withSaved($uuid, fn (TargetConnection $target) => $this->diagnostics->configAudit($this->session->auditContext(), $uuid, $target), DatabaseParam::fromQuery($request), DatabaseParam::schemaFromQuery($request), DatabaseParam::tabletFromQuery($request));

        return new JsonResponse(DiagnosticsPresenter::configAudit($report));
    }

    /**
     * Écart de variables entre deux connexions du demandeur, de même moteur : `a` et `b` sont deux identifiants de connexion.
     */
    #[Route('/web/diagnostics/compare', methods: ['GET'])]
    public function compare(Request $request): JsonResponse
    {
        $a = $request->query->get('a');
        $b = $request->query->get('b');
        if (!\is_string($a) || !\is_string($b) || !Uuid::isValid($a) || !Uuid::isValid($b)) {
            throw new InvalidVariableQueryException('Two connection identifiers are needed (a and b).');
        }
        $idA = Uuid::fromString($a);
        $idB = Uuid::fromString($b);
        if ($idA->equals($idB)) {
            throw new InvalidVariableQueryException('Two different connections are needed.');
        }
        $comparison = $this->targets->withSaved($idA, fn (TargetConnection $targetA) => $this->targets->withSaved($idB, fn (TargetConnection $targetB) => $this->diagnostics->compareVariables($this->session->auditContext(), $idA, $targetA, $idB, $targetB)));

        return new JsonResponse(DiagnosticsPresenter::comparison($comparison));
    }

    #[Route('/web/connections/{id}/diagnostics/accounts', requirements: self::ID, methods: ['GET'])]
    public function accounts(string $id, Request $request): JsonResponse
    {
        $uuid = Uuid::fromString($id);
        $report = $this->targets->withSaved($uuid, fn (TargetConnection $target) => $this->diagnostics->accounts($this->session->auditContext(), $uuid, $target), DatabaseParam::fromQuery($request), DatabaseParam::schemaFromQuery($request), DatabaseParam::tabletFromQuery($request));

        return new JsonResponse(DiagnosticsPresenter::accounts($report));
    }

    #[Route('/web/connections/{id}/diagnostics/encryption', requirements: self::ID, methods: ['GET'])]
    public function encryption(string $id, Request $request): JsonResponse
    {
        $uuid = Uuid::fromString($id);
        $encryption = $this->targets->withSaved($uuid, fn (TargetConnection $target) => $this->diagnostics->encryption($this->session->auditContext(), $uuid, $target), DatabaseParam::fromQuery($request), DatabaseParam::schemaFromQuery($request), DatabaseParam::tabletFromQuery($request));

        return new JsonResponse(DiagnosticsPresenter::encryption($encryption));
    }

    #[Route('/web/connections/{id}/diagnostics/health', requirements: self::ID, methods: ['GET'])]
    public function health(string $id, Request $request): JsonResponse
    {
        $uuid = Uuid::fromString($id);
        $seconds = HealthInterval::parse($request->query->get('seconds'));
        $report = $this->targets->withSaved($uuid, fn (TargetConnection $target) => $this->diagnostics->health($this->session->auditContext(), $uuid, $target, $seconds), DatabaseParam::fromQuery($request), DatabaseParam::schemaFromQuery($request), DatabaseParam::tabletFromQuery($request));

        return new JsonResponse(DiagnosticsPresenter::health($report));
    }

    #[Route('/web/connections/{id}/diagnostics/replication', requirements: self::ID, methods: ['GET'])]
    public function replication(string $id, Request $request): JsonResponse
    {
        $uuid = Uuid::fromString($id);
        $report = $this->targets->withSaved($uuid, fn (TargetConnection $target) => $this->diagnostics->replication($this->session->auditContext(), $uuid, $target), DatabaseParam::fromQuery($request), DatabaseParam::schemaFromQuery($request), DatabaseParam::tabletFromQuery($request));

        return new JsonResponse(DiagnosticsPresenter::replication($report, $this->masking->enabled));
    }

    #[Route('/web/connections/{id}/diagnostics/logs', requirements: self::ID, methods: ['GET'])]
    public function logs(string $id, Request $request): JsonResponse
    {
        $uuid = Uuid::fromString($id);
        [$error, $slow] = $this->targets->withSaved($uuid, fn (TargetConnection $target) => $this->diagnostics->logs($this->session->auditContext(), $uuid, $target), DatabaseParam::fromQuery($request), DatabaseParam::schemaFromQuery($request), DatabaseParam::tabletFromQuery($request));

        return new JsonResponse(DiagnosticsPresenter::logs($error, $slow));
    }
}

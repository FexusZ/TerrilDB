<?php

declare(strict_types=1);

namespace App\Http\Api;

use App\ApiToken\TokenConnections;
use App\Audit\AuditActor;
use App\Audit\AuditedDiagnostics;
use App\Audit\AuditedQueries;
use App\Connection\ConnectionManager;
use App\Connection\Exception\ConnectionNotFoundException;
use App\Core\Driver\TargetConnection;
use App\Core\Query\Exception\InvalidQueryOptionsException;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryOptions;
use App\Entity\DatabaseConnection;
use App\Http\Json\ConnectionInput;
use App\Http\Json\DiagnosticsPresenter;
use App\Http\Json\JsonInput;
use App\Http\Json\ResultPresenter;
use App\Http\Json\SchemaPresenter;
use App\Http\Json\TargetSession;
use App\Http\Web\TokenPresenter;
use App\Http\Web\UserPresenter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * L'API REST. Elle n'ouvre que les connexions listées sur le jeton, une connexion hors liste étant indiscernable
 * d'une connexion inexistante. `/query` reste en lecture seule pour tous les jetons (aucune confirmation ne lève un
 * refus du garde) ; `/execute` écrit, dans les seuls droits du jeton et avec confirmation explicite du destructif.
 */
final readonly class ApiController
{
    private const ID = ['id' => '[0-9a-fA-F-]{36}'];

    public function __construct(
        private ApiRequestAccess $request,
        private ConnectionManager $connections,
        private TargetSession $targets,
        private AuditedQueries $queries,
        private TokenConnections $created,
        private AuditedDiagnostics $diagnostics,
    ) {
    }

    #[Route('/api/v1/token', methods: ['GET'])]
    public function token(): JsonResponse
    {
        $access = $this->request->get();

        return new JsonResponse(['token' => TokenPresenter::present($access->token), 'user' => UserPresenter::present($access->user)]);
    }

    #[Route('/api/v1/connections', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $access = $this->request->get();
        $granted = array_filter(
            $this->connections->list($access->user),
            static fn (DatabaseConnection $connection): bool => $access->token->allows($connection->getId()),
        );

        return new JsonResponse(['connections' => array_values(array_map(
            static fn (DatabaseConnection $connection): array => ['id' => $connection->getId()->toRfc4122(), 'name' => $connection->getName(), 'engine' => $connection->getEngine()->value],
            $granted,
        ))]);
    }

    #[Route('/api/v1/connections', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        [$name, $engine, $parameters] = ConnectionInput::read(JsonInput::fromRequest($request), null);
        $connection = $this->created->create($this->request->get(), $this->request->auditContext(AuditActor::Api), $name, $engine, $parameters);

        return new JsonResponse(['connection' => ['id' => $connection->getId()->toRfc4122(), 'name' => $connection->getName(), 'engine' => $connection->getEngine()->value]], 201);
    }

    #[Route('/api/v1/connections/{id}/tables', requirements: self::ID, methods: ['GET'])]
    public function tables(string $id): JsonResponse
    {
        $tables = $this->open($id, static fn (TargetConnection $target): array => $target->introspector()->listTables());

        return new JsonResponse(['tables' => array_map(SchemaPresenter::summary(...), $tables)]);
    }

    #[Route('/api/v1/connections/{id}/tables/{name}', requirements: self::ID + ['name' => '.+'], methods: ['GET'])]
    public function table(string $id, string $name): JsonResponse
    {
        $table = $this->open($id, static fn (TargetConnection $target) => $target->introspector()->describeTable($name));

        return new JsonResponse(['table' => SchemaPresenter::table($table)]);
    }

    #[Route('/api/v1/connections/{id}/query', requirements: self::ID, methods: ['POST'])]
    public function query(string $id, Request $request): JsonResponse
    {
        $input = JsonInput::fromRequest($request);
        $sql = $input->string('sql');
        $mode = $input->optionalString('mode');
        if (null !== $mode && QueryMode::ReadOnly->value !== $mode) {
            throw new InvalidQueryOptionsException('The API only runs read-only queries.');
        }
        $defaults = new QueryOptions();
        $options = new QueryOptions(
            QueryMode::ReadOnly,
            $input->optionalInt('timeoutMs', $defaults->timeoutMs) ?? $defaults->timeoutMs,
            $input->optionalInt('maxRows', $defaults->maxRows) ?? $defaults->maxRows,
        );

        $result = $this->open($id, fn (TargetConnection $target) => $this->queries->execute($this->request->auditContext(AuditActor::Api), Uuid::fromString($id), $target, $sql, $options));

        return new JsonResponse(ResultPresenter::present($result));
    }

    #[Route('/api/v1/connections/{id}/execute', requirements: self::ID, methods: ['POST'])]
    public function execute(string $id, Request $request): JsonResponse
    {
        $input = JsonInput::fromRequest($request);
        $sql = $input->string('sql');
        $confirmed = $input->bool('confirmed', false);
        $defaults = new QueryOptions();
        $options = $this->request->get()->writeOptions(
            $input->optionalInt('timeoutMs', $defaults->timeoutMs) ?? $defaults->timeoutMs,
            $input->optionalInt('maxRows', $defaults->maxRows) ?? $defaults->maxRows,
        );

        $result = $this->open($id, fn (TargetConnection $target) => $this->queries->execute($this->request->auditContext(AuditActor::Api), Uuid::fromString($id), $target, $sql, $options, $confirmed));

        return new JsonResponse(ResultPresenter::present($result));
    }

    /**
     * Ce que chaque source de supervision peut lire pour cette connexion. Réservé aux jetons qui ont le droit `diagnostic`.
     */
    #[Route('/api/v1/connections/{id}/diagnostics', requirements: self::ID, methods: ['GET'])]
    public function diagnostics(string $id): JsonResponse
    {
        $this->request->get()->requireDiagnostic();
        $capabilities = $this->open($id, static fn (TargetConnection $target): array => $target->diagnostics()->capabilities());

        return new JsonResponse(['capabilities' => DiagnosticsPresenter::capabilities($capabilities)]);
    }

    /**
     * Les sessions en cours sur le serveur de la connexion, en lecture seule, textes de requêtes masqués puis raccourcis.
     */
    #[Route('/api/v1/connections/{id}/diagnostics/sessions', requirements: self::ID, methods: ['GET'])]
    public function sessions(string $id): JsonResponse
    {
        $this->request->get()->requireDiagnostic();
        $report = $this->open($id, fn (TargetConnection $target) => $this->diagnostics->sessions($this->request->auditContext(AuditActor::Api), Uuid::fromString($id), $target));

        return new JsonResponse(DiagnosticsPresenter::sessions($report));
    }

    /**
     * @template T
     *
     * @param \Closure(TargetConnection): T $action
     *
     * @return T
     */
    private function open(string $id, \Closure $action): mixed
    {
        $access = $this->request->get();
        $connectionId = Uuid::fromString($id);
        if (!$access->token->allows($connectionId)) {
            throw ConnectionNotFoundException::create();
        }

        return $this->targets->with($access->user, $access->key, $connectionId, $action);
    }
}

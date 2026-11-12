<?php

declare(strict_types=1);

namespace App\Http\Mcp;

use App\ApiToken\TokenConnections;
use App\Audit\AuditActor;
use App\Audit\AuditedDiagnostics;
use App\Audit\AuditedQueries;
use App\Connection\ConnectionManager;
use App\Connection\Exception\ConnectionNotFoundException;
use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Connection\Exception\InvalidConnectionParametersException;
use App\Core\Driver\TargetConnection;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryOptions;
use App\Entity\DatabaseConnection;
use App\Http\Api\ApiRequestAccess;
use App\Http\Json\DiagnosticsPresenter;
use App\Http\Json\ErrorMapper;
use App\Http\Json\HealthInterval;
use App\Http\Json\ResultPresenter;
use App\Http\Json\SchemaPresenter;
use App\Http\Json\TargetSession;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Uid\Uuid;

/**
 * Les outils MCP : les mêmes opérations que l'API REST pour les connexions du jeton (lecture seule, sauf
 * `execute_statement`, que seuls les jetons qui ont un droit d'écriture voient). Un échec
 * est rendu à l'assistant comme une erreur d'outil (code stable, motif du refus), jamais comme un détail interne.
 */
final readonly class McpTools
{
    public function __construct(
        private ApiRequestAccess $request,
        private ConnectionManager $connections,
        private TargetSession $targets,
        private AuditedQueries $queries,
        private ErrorMapper $errors,
        private TokenConnections $created,
        private AuditedDiagnostics $diagnostics,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function listConnections(): array
    {
        return $this->guard(function (): array {
            $access = $this->request->get();
            $granted = array_filter(
                $this->connections->list($access->user),
                static fn (DatabaseConnection $connection): bool => $access->token->allows($connection->getId()),
            );

            return ['connections' => array_values(array_map(
                static fn (DatabaseConnection $connection): array => ['id' => $connection->getId()->toRfc4122(), 'name' => $connection->getName(), 'engine' => $connection->getEngine()->value],
                $granted,
            ))];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function listTables(string $connection): array
    {
        return $this->guard(fn (): array => [
            'tables' => array_map(SchemaPresenter::summary(...), $this->open($connection, static fn (TargetConnection $target): array => $target->introspector()->listTables())),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function describeTable(string $connection, string $table): array
    {
        return $this->guard(fn (): array => [
            'table' => SchemaPresenter::table($this->open($connection, static fn (TargetConnection $target) => $target->introspector()->describeTable($table))),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function runQuery(string $connection, string $sql, ?int $maxRows = null, ?int $timeoutMs = null): array
    {
        return $this->guard(function () use ($connection, $sql, $maxRows, $timeoutMs): array {
            $defaults = new QueryOptions();
            $options = new QueryOptions(QueryMode::ReadOnly, $timeoutMs ?? $defaults->timeoutMs, $maxRows ?? $defaults->maxRows);

            return ResultPresenter::present($this->open($connection, fn (TargetConnection $target) => $this->queries->execute($this->request->auditContext(AuditActor::Mcp), Uuid::fromString($connection), $target, $sql, $options)));
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function createConnection(string $name, string $engine, string $host, string $database, string $username, string $password, ?int $port = null): array
    {
        return $this->guard(function () use ($name, $engine, $host, $database, $username, $password, $port): array {
            $driver = Engine::tryFrom($engine) ?? throw new InvalidConnectionParametersException('The engine is not supported.');
            $connection = $this->created->create(
                $this->request->get(),
                $this->request->auditContext(AuditActor::Mcp),
                $name,
                $driver,
                new ConnectionParameters($host, $port ?? $driver->defaultPort(), $database, $username, $password),
            );

            return ['connection' => ['id' => $connection->getId()->toRfc4122(), 'name' => $connection->getName(), 'engine' => $connection->getEngine()->value]];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function executeStatement(string $connection, string $sql, ?bool $confirmed = null, ?int $maxRows = null, ?int $timeoutMs = null): array
    {
        return $this->guard(function () use ($connection, $sql, $confirmed, $maxRows, $timeoutMs): array {
            $defaults = new QueryOptions();
            $options = $this->request->get()->writeOptions($timeoutMs ?? $defaults->timeoutMs, $maxRows ?? $defaults->maxRows);

            return ResultPresenter::present($this->open($connection, fn (TargetConnection $target) => $this->queries->execute($this->request->auditContext(AuditActor::Mcp), Uuid::fromString($connection), $target, $sql, $options, $confirmed ?? false)));
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function listSessions(string $connection): array
    {
        return $this->guard(function () use ($connection): array {
            $this->request->get()->requireDiagnostic();

            return DiagnosticsPresenter::sessions($this->open($connection, fn (TargetConnection $target) => $this->diagnostics->sessions($this->request->auditContext(AuditActor::Mcp), Uuid::fromString($connection), $target)));
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function listLocks(string $connection): array
    {
        return $this->guard(function () use ($connection): array {
            $this->request->get()->requireDiagnostic();

            return DiagnosticsPresenter::locks($this->open($connection, fn (TargetConnection $target) => $this->diagnostics->locks($this->request->auditContext(AuditActor::Mcp), Uuid::fromString($connection), $target)));
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function serverHealth(string $connection, ?int $seconds = null): array
    {
        return $this->guard(function () use ($connection, $seconds): array {
            $this->request->get()->requireDiagnostic();
            $interval = HealthInterval::parse($seconds);

            return DiagnosticsPresenter::health($this->open($connection, fn (TargetConnection $target) => $this->diagnostics->health($this->request->auditContext(AuditActor::Mcp), Uuid::fromString($connection), $target, $interval)));
        });
    }

    /**
     * @template T
     *
     * @param \Closure(TargetConnection): T $action
     *
     * @return T
     */
    private function open(string $connection, \Closure $action): mixed
    {
        $access = $this->request->get();
        if (!Uuid::isValid($connection) || !$access->token->allows(Uuid::fromString($connection))) {
            throw ConnectionNotFoundException::create();
        }

        return $this->targets->with($access->user, $access->key, Uuid::fromString($connection), $action);
    }

    /**
     * @template T
     *
     * @param \Closure(): T $action
     *
     * @return T
     */
    private function guard(\Closure $action): mixed
    {
        try {
            return $action();
        } catch (\Throwable $error) {
            $mapped = $this->errors->map($error);
            if (null === $mapped) {
                throw new ToolCallException('internal_error', previous: $error);
            }

            throw new ToolCallException(json_encode(['error' => $mapped->code] + $mapped->extra, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE), previous: $error);
        }
    }
}

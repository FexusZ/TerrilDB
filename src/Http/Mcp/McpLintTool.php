<?php

declare(strict_types=1);

namespace App\Http\Mcp;

use App\Audit\AuditActor;
use App\Audit\AuditedLint;
use App\Connection\Exception\ConnectionNotFoundException;
use App\Core\Driver\TargetConnection;
use App\Http\Api\ApiRequestAccess;
use App\Http\Json\ErrorMapper;
use App\Http\Json\LintPresenter;
use App\Http\Json\TargetSession;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Uid\Uuid;

/**
 * L'outil MCP `lint_query` : les conseils du linter (AuditedLint, comme l'interface web et l'API) sur un texte SQL. Lecture seule,
 * visible des seuls jetons qui ont le droit `readSchema` (les conseils citent des noms d'index) : rien n'est exécuté, la cible n'est lue que pour la structure d'une table.
 */
final readonly class McpLintTool
{
    public function __construct(
        private ApiRequestAccess $request,
        private TargetSession $targets,
        private AuditedLint $lint,
        private ErrorMapper $errors,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function lintQuery(string $connection, string $sql): array
    {
        try {
            $access = $this->request->get();
            $access->requireReadSchema();
            if (!Uuid::isValid($connection) || !$access->token->allows(Uuid::fromString($connection))) {
                throw ConnectionNotFoundException::create();
            }
            $id = Uuid::fromString($connection);
            $findings = $this->targets->with($access->user, $access->key, $id, fn (TargetConnection $target): array => $this->lint->lint($this->request->auditContext(AuditActor::Mcp), $id, $target, $sql));

            return ['findings' => LintPresenter::findings($findings)];
        } catch (\Throwable $error) {
            $mapped = $this->errors->map($error);
            if (null === $mapped) {
                throw new ToolCallException('internal_error', previous: $error);
            }

            throw new ToolCallException(json_encode(['error' => $mapped->code] + $mapped->extra, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE), previous: $error);
        }
    }
}

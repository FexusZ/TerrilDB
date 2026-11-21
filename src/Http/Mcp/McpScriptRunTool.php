<?php

declare(strict_types=1);

namespace App\Http\Mcp;

use App\Audit\AuditActor;
use App\Connection\Exception\ConnectionNotFoundException;
use App\Http\Api\ApiRequestAccess;
use App\Http\Json\ErrorMapper;
use App\Http\Json\ScriptRunPresenter;
use App\ScriptRun\Exception\ScriptRunNotFoundException;
use App\ScriptRun\TokenScriptRuns;
use Mcp\Server\Exception\ToolCallException;
use Symfony\Component\Uid\Uuid;

/**
 * Les outils MCP des scripts détachés (#169) : `run_script` (après `prepare_script`, même ticket qu'`execute_script`), `get_script_run`
 * et `cancel_script_run` (arrêt entre deux instructions, jamais de KILL). Visibles des seuls jetons qui écrivent ; seul le jeton lanceur suit l'exécution.
 */
final readonly class McpScriptRunTool
{
    public function __construct(
        private ApiRequestAccess $request,
        private TokenScriptRuns $runs,
        private ErrorMapper $errors,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function runScript(string $connection, string $script, ?string $confirmationToken = null, ?bool $confirmed = null, ?bool $dryRun = null, ?int $timeoutMs = null): array
    {
        return $this->guard(function () use ($connection, $script, $confirmationToken, $confirmed, $dryRun, $timeoutMs): array {
            if (!Uuid::isValid($connection)) {
                throw ConnectionNotFoundException::create();
            }
            $run = $this->runs->launch($this->request->get(), $this->request->auditContext(AuditActor::Mcp), Uuid::fromString($connection), $script, $confirmationToken, $confirmed ?? false, $dryRun ?? false, $timeoutMs);

            return ['run' => ScriptRunPresenter::run($run)];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function getScriptRun(string $run): array
    {
        return $this->guard(fn (): array => ['run' => ScriptRunPresenter::run($this->runs->read($this->request->get(), self::id($run)))]);
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelScriptRun(string $run): array
    {
        return $this->guard(fn (): array => ['run' => ScriptRunPresenter::run($this->runs->cancel($this->request->get(), self::id($run)))]);
    }

    private static function id(string $run): Uuid
    {
        if (!Uuid::isValid($run)) {
            throw new ScriptRunNotFoundException('No such run.');
        }

        return Uuid::fromString($run);
    }

    /**
     * @param \Closure(): array<string, mixed> $action
     *
     * @return array<string, mixed>
     */
    private function guard(\Closure $action): array
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

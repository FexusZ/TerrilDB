<?php

declare(strict_types=1);

namespace App\ScriptRun;

use App\ApiToken\ApiTokenAccess;
use App\Audit\AuditContext;
use App\Confirmation\WriteApproval;
use App\Connection\Exception\ConnectionNotFoundException;
use App\Core\Driver\TargetConnection;
use App\Core\Query\Exception\InvalidQueryOptionsException;
use App\Core\Query\QueryOptions;
use App\Entity\ScriptRun;
use App\Http\Json\TargetSession;
use Symfony\Component\Uid\Uuid;

/**
 * Les scripts détachés d'un jeton d'API ou MCP (#169) : même jugement et MÊME ticket de confirmation qu'`execute_script` (`prepare_script`
 * d'abord, le ticket est lié au texte exact et consommé), droits d'écriture du jeton, puis l'exécution est confiée au worker. Le jeton
 * lanceur est seul à suivre et arrêter l'exécution ; le propriétaire du compte la voit toujours sur le web.
 */
final readonly class TokenScriptRuns
{
    /** Comme la console web : au-delà de la durée d'une requête, c'est le but d'une exécution détachée. */
    public const int DEFAULT_TIMEOUT_MS = 600_000;

    public function __construct(
        private TargetSession $targets,
        private WriteApproval $approval,
        private ScriptRunStarter $starter,
        private ScriptRunAccess $access,
    ) {
    }

    /**
     * @throws \App\ApiToken\Exception\ApiTokenPermissionException jeton sans droit d'écriture
     * @throws ConnectionNotFoundException
     * @throws InvalidQueryOptionsException                        délai hors bornes (vérifié AVANT de consommer le ticket)
     */
    public function launch(ApiTokenAccess $token, AuditContext $context, Uuid $connectionId, string $script, ?string $ticket, bool $confirmed, bool $dryRun, ?int $timeoutMs): ScriptRun
    {
        $writes = $token->writeOptions(QueryOptions::MIN_TIMEOUT_MS, 1)->writes;
        if (!$token->token->allows($connectionId)) {
            throw ConnectionNotFoundException::create();
        }
        $timeout = $timeoutMs ?? self::DEFAULT_TIMEOUT_MS;
        if ($timeout < QueryOptions::MIN_TIMEOUT_MS || $timeout > QueryOptions::MAX_TIMEOUT_MS) {
            throw new InvalidQueryOptionsException('timeoutMs is out of bounds.');
        }

        $this->targets->with($token->user, $token->key, $connectionId, fn (TargetConnection $target) => $this->approval->authorizeScript($token, $context, $connectionId, $target, $script, $ticket, $confirmed));

        return $this->starter->start($token->user, $token->key, $context, $connectionId, $script, $confirmed, $dryRun, $timeout, null, null, false, $writes, $token->token->getId());
    }

    public function read(ApiTokenAccess $token, Uuid $id): ScriptRun
    {
        $token->writeOptions(QueryOptions::MIN_TIMEOUT_MS, 1);

        return $this->access->readLaunchedBy($token->token->getId(), $id);
    }

    public function cancel(ApiTokenAccess $token, Uuid $id): ScriptRun
    {
        $token->writeOptions(QueryOptions::MIN_TIMEOUT_MS, 1);

        return $this->access->cancelLaunchedBy($token->token->getId(), $id);
    }
}

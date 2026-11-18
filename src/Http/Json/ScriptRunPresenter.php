<?php

declare(strict_types=1);

namespace App\Http\Json;

use App\Entity\ScriptRun;

/**
 * La forme JSON du suivi d'une exécution détachée. Ni le texte du script, ni celui d'une instruction, ni un message du moteur : le rang,
 * l'état, des nombres et le code SQLSTATE à cinq caractères.
 */
final class ScriptRunPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function run(ScriptRun $run): array
    {
        return [
            'id' => $run->getId()->toRfc4122(),
            'connectionId' => $run->getConnectionId()->toRfc4122(),
            'status' => $run->getStatus()->value,
            'finished' => $run->getStatus()->isTerminal(),
            'dryRun' => $run->isDryRun(),
            'total' => $run->getTotal(),
            'completed' => $run->getCompleted(),
            'cancelRequested' => $run->isCancelRequested(),
            'statements' => $run->getStatements(),
            'createdAt' => $run->getCreatedAt()->format(\DATE_ATOM),
            'startedAt' => $run->getStartedAt()?->format(\DATE_ATOM),
            'heartbeatAt' => $run->getHeartbeatAt()->format(\DATE_ATOM),
            'finishedAt' => $run->getFinishedAt()?->format(\DATE_ATOM),
        ];
    }
}

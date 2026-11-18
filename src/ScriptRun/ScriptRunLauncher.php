<?php

declare(strict_types=1);

namespace App\ScriptRun;

use App\ScriptRun\Channel\ScriptWorkerBusyException;
use App\ScriptRun\Channel\ScriptWorkerUnavailableException;

/**
 * Ce que la requête web sait faire pour lancer une exécution : la confier au worker. L'implémentation réelle est le socket Unix
 * (`ScriptRunDispatcher`) ; les tests des routes en mettent une qui note le message.
 */
interface ScriptRunLauncher
{
    /**
     * @throws ScriptWorkerUnavailableException l'exécution n'a pas démarré
     * @throws ScriptWorkerBusyException        l'exécution n'a pas démarré, on peut réessayer
     */
    public function dispatch(ScriptRunRequest $request): void;
}

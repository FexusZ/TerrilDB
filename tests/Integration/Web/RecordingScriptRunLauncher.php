<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\ScriptRun\Channel\ScriptWorkerBusyException;
use App\ScriptRun\Channel\ScriptWorkerUnavailableException;
use App\ScriptRun\ScriptRunLauncher;
use App\ScriptRun\ScriptRunRequest;

/**
 * Le lanceur de test des routes web : un worker dans un autre processus ne verrait pas la base du test (transaction annulée),
 * on note donc ce que la route lui enverrait. Statique : le conteneur est redémarré à chaque requête, pas le processus PHP.
 */
final class RecordingScriptRunLauncher implements ScriptRunLauncher
{
    /** @var list<ScriptRunRequest> */
    public static array $requests = [];
    /** `busy`, `unavailable` ou null (accepte). */
    public static ?string $answer = null;

    public static function reset(): void
    {
        self::$requests = [];
        self::$answer = null;
    }

    public static function last(): ScriptRunRequest
    {
        $last = end(self::$requests);
        if (false === $last) {
            throw new \LogicException('Nothing was dispatched.');
        }

        return $last;
    }

    public function dispatch(ScriptRunRequest $request): void
    {
        match (self::$answer) {
            'busy' => throw new ScriptWorkerBusyException('busy'),
            'unavailable' => throw new ScriptWorkerUnavailableException('unavailable'),
            default => self::$requests[] = $request,
        };
    }
}

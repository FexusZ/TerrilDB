<?php

declare(strict_types=1);

namespace App\ScriptRun\Channel;

/**
 * Le worker ne répond pas (arrêté, socket absent, message refusé) : l'exécution n'a PAS démarré.
 */
final class ScriptWorkerUnavailableException extends \RuntimeException
{
}

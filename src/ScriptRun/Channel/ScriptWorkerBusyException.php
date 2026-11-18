<?php

declare(strict_types=1);

namespace App\ScriptRun\Channel;

/**
 * Le worker joue déjà le nombre maximal d'exécutions : celle-ci n'a PAS démarré, on peut réessayer plus tard.
 */
final class ScriptWorkerBusyException extends \RuntimeException
{
}

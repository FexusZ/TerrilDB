<?php

declare(strict_types=1);

namespace App\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\LockConflict;

/**
 * Lit le code du moteur dans une erreur PDO (ou DBAL qui l'enveloppe) pour y reconnaître un verrou.
 */
final class EngineLockConflict
{
    public static function of(Engine $engine, \Throwable $error): ?LockConflict
    {
        for ($e = $error; null !== $e; $e = $e->getPrevious()) {
            if ($e instanceof \PDOException && \is_array($e->errorInfo)) {
                $state = $e->errorInfo[0] ?? null;
                $code = $e->errorInfo[1] ?? null;

                return LockConflict::fromEngineError($engine, \is_string($state) ? $state : null, \is_int($code) ? $code : null);
            }
        }

        return null;
    }
}

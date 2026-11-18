<?php

declare(strict_types=1);

namespace App\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\EngineFault;

/**
 * Lit le code du moteur dans une erreur PDO (ou DBAL qui l'enveloppe) pour y reconnaître une erreur courante.
 */
final class EngineFaultReader
{
    public static function of(Engine $engine, \Throwable $error): ?EngineFault
    {
        $pdo = self::pdo($error);

        return null === $pdo ? null : EngineFault::fromEngineError($engine, self::state($pdo), self::code($pdo));
    }

    /** À l'ouverture : libpq noie le SQLSTATE de PostgreSQL, le texte aide alors à classer (il n'est jamais montré). */
    public static function ofConnection(Engine $engine, \Throwable $error): ?EngineFault
    {
        $pdo = self::pdo($error);

        return null === $pdo ? null : EngineFault::fromConnectionError($engine, self::state($pdo), self::code($pdo), $pdo->getMessage());
    }

    private static function pdo(\Throwable $error): ?\PDOException
    {
        for ($e = $error; null !== $e; $e = $e->getPrevious()) {
            if ($e instanceof \PDOException && \is_array($e->errorInfo)) {
                return $e;
            }
        }

        return null;
    }

    private static function state(\PDOException $e): ?string
    {
        $state = \is_array($e->errorInfo) ? ($e->errorInfo[0] ?? null) : null;

        return \is_string($state) ? $state : null;
    }

    private static function code(\PDOException $e): ?int
    {
        $code = \is_array($e->errorInfo) ? ($e->errorInfo[1] ?? null) : null;

        return \is_int($code) ? $code : null;
    }
}

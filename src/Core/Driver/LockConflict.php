<?php

declare(strict_types=1);

namespace App\Core\Driver;

use App\Core\Connection\Engine;

/**
 * Ce qu'un verrou a fait échouer, d'après le CODE du moteur (jamais son texte). Les deux cas se rejouent mais pas pareil :
 * un interblocage a fait annuler toute la transaction (on rejoue l'opération entière), un délai de verrou dépassé laisse
 * l'autre transaction en cours (on regarde qui bloque, puis on réessaie). Un délai d'exécution (PostgreSQL 57014, MySQL 3024)
 * n'en fait pas partie : il reste `query_timeout`.
 */
enum LockConflict: string
{
    case Deadlock = 'deadlock';
    case SerializationFailure = 'serialization_failure';
    case LockTimeout = 'lock_timeout';

    /**
     * MySQL / MariaDB : 1213 (interblocage), 1205 (délai de verrou). PostgreSQL : SQLSTATE 40P01 (interblocage), 40001 (échec de
     * sérialisation : la transaction est annulée et se rejoue, mais ce n'est pas un interblocage), 55P03 (lock_not_available, posé par `lock_timeout`).
     */
    public static function fromEngineError(Engine $engine, ?string $sqlState, ?int $code): ?self
    {
        return match ($engine) {
            Engine::MySql, Engine::MariaDb => match ($code) {
                1213 => self::Deadlock,
                1205 => self::LockTimeout,
                default => null,
            },
            Engine::PostgreSql => match ($sqlState) {
                '40P01' => self::Deadlock,
                '40001' => self::SerializationFailure,
                '55P03' => self::LockTimeout,
                default => null,
            },
            default => null,
        };
    }

    public function code(): string
    {
        return $this->value;
    }
}

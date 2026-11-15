<?php

declare(strict_types=1);

namespace App\Core\Driver;

use App\Core\Connection\Engine;

/**
 * Une erreur courante du moteur, reconnue d'après son CODE (jamais d'après son texte, sauf à la connexion PostgreSQL, voir
 * `fromConnectionError`) et rendue sous un code stable avec un conseil. Le message du moteur n'est jamais montré : il peut citer
 * une valeur, un hôte ou un compte. Distinct de `LockConflict` (verrous) et du 403 `permission_denied` des jetons d'API : ici
 * c'est la base cible qui refuse le droit au compte de la connexion.
 */
enum EngineFault: string
{
    case TooManyConnections = 'too_many_connections';
    case PermissionDenied = 'engine_permission_denied';
    case DuplicateKey = 'duplicate_key';
    case AuthRequiresTls = 'auth_requires_tls';
    case AuthenticationFailed = 'auth_failed';

    /** Délai conseillé avant de réessayer quand le serveur n'a plus de place. */
    private const RETRY_AFTER_SECONDS = 5;

    /**
     * MySQL / MariaDB : 1040 (trop de connexions), 1203 et 1226 (limite du compte : connexions simultanées, ou autre ressource plafonnée), 1044 / 1142 / 1143 / 1227 / 1370 (droit refusé sur
     * base, table, colonne, privilège, routine), 1062 (doublon), 1045 (identifiants refusés), 3159 (transport non chiffré
     * interdit). PostgreSQL : SQLSTATE 53300, 42501, 23505, 28P01.
     */
    public static function fromEngineError(Engine $engine, ?string $sqlState, ?int $code): ?self
    {
        return match ($engine) {
            Engine::MySql, Engine::MariaDb => match ($code) {
                1040, 1203, 1226 => self::TooManyConnections,
                1044, 1142, 1143, 1227, 1370 => self::PermissionDenied,
                1062 => self::DuplicateKey,
                1045 => self::AuthenticationFailed,
                3159 => self::AuthRequiresTls,
                default => null,
            },
            Engine::PostgreSql => match ($sqlState) {
                '53300' => self::TooManyConnections,
                '42501' => self::PermissionDenied,
                '23505' => self::DuplicateKey,
                '28P01' => self::AuthenticationFailed,
                default => null,
            },
            default => null,
        };
    }

    /**
     * À l'OUVERTURE d'une connexion PostgreSQL, libpq range tout refus du serveur sous le SQLSTATE 08006 : le code ne dit plus rien
     * (mesuré), seul le texte distingue un mot de passe refusé, un serveur plein et un TLS exigé. Le texte sert ici à CLASSER, jamais
     * à être montré. MySQL / MariaDB gardent leurs codes.
     */
    public static function fromConnectionError(Engine $engine, ?string $sqlState, ?int $code, string $message): ?self
    {
        $byCode = self::fromEngineError($engine, $sqlState, $code);
        if (null !== $byCode || Engine::PostgreSql !== $engine || '08006' !== $sqlState) {
            return $byCode;
        }

        // Le refus du serveur suit « FATAL: » ; ce qui précède cite l'hôte, ce qui suit peut citer un nom de base ou de rôle CHOISI par
        // l'utilisateur : seule la tournure qui OUVRE le message du serveur compte (un nom cité plus loin ne classe rien).
        $position = strpos($message, 'FATAL:');
        if (false === $position) {
            return null;
        }
        $refusal = trim((string) preg_replace('/\s+/', ' ', substr($message, $position + 6)));

        return match (true) {
            1 === preg_match('/^(?:no pg_hba\.conf entry .*, no encryption$|SSL\/TLS required|SSL (?:connection )?is required)/i', $refusal) => self::AuthRequiresTls,
            1 === preg_match('/^(?:(?:password |ident |peer )?authentication failed|no password supplied)/i', $refusal) => self::AuthenticationFailed,
            1 === preg_match('/^(?:sorry, too many clients|remaining connection slots are reserved|too many connections for )/i', $refusal) => self::TooManyConnections,
            1 === preg_match('/^permission denied for database /i', $refusal) => self::PermissionDenied,
            default => null,
        };
    }

    public function code(): string
    {
        return $this->value;
    }

    /** Seul un serveur plein se réessaie tel quel ; un droit, un doublon ou des identifiants refusés ne changent pas en attendant. */
    public function retryable(): bool
    {
        return self::TooManyConnections === $this;
    }

    public function retryAfterSeconds(): ?int
    {
        return self::TooManyConnections === $this ? self::RETRY_AFTER_SECONDS : null;
    }
}

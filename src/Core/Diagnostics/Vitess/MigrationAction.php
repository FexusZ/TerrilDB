<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Vitess;

/**
 * Ce qu'on peut demander à une migration de DDL en ligne. TerrilDB n'ajoute AUCUNE voie d'écriture : une action est une instruction
 * `ALTER VITESS_MIGRATION '<uuid>' …` que l'utilisateur envoie par la console, ou un jeton qui écrit la structure par `prepare` puis
 * `execute` (le garde la juge comme une instruction de structure, avec sa confirmation). Ici on ne fait que l'écrire correctement.
 */
enum MigrationAction: string
{
    case Launch = 'launch';
    case Cancel = 'cancel';
    case Retry = 'retry';
    case Complete = 'complete';

    private const UUID = '/^[0-9a-f]{8}_[0-9a-f]{4}_[0-9a-f]{4}_[0-9a-f]{4}_[0-9a-f]{12}$/D';

    public static function isUuid(string $value): bool
    {
        return 1 === preg_match(self::UUID, $value);
    }

    /**
     * @throws \InvalidArgumentException si l'identifiant n'a pas la forme d'un UUID de migration (il vient du serveur : donnée hostile)
     */
    public function statement(string $uuid): string
    {
        if (!self::isUuid($uuid)) {
            throw new \InvalidArgumentException('Not a migration UUID.');
        }

        return \sprintf("ALTER VITESS_MIGRATION '%s' %s", $uuid, strtoupper($this->value));
    }
}

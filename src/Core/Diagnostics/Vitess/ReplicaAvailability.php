<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Vitess;

/**
 * Peut-on lire sur `@replica` ? `Available` : un tablet REPLICA en service existe pour le keyspace de la connexion. `Missing` :
 * vtgate n'en connaît aucun (la cible `@replica` échouerait). `NotVitess` : le serveur n'est pas un vtgate. `Unknown` : la
 * vérification n'a pas pu se faire (serveur injoignable, droit manquant) : on ne refuse alors rien.
 */
enum ReplicaAvailability: string
{
    case Available = 'available';
    case Missing = 'missing';
    case NotVitess = 'not_vitess';
    case Unknown = 'unknown';

    /**
     * @param list<VitessTablet> $tablets ce que `SHOW VITESS_TABLETS` rend
     */
    public static function fromTablets(string $database, array $tablets): self
    {
        // `shop`, `shop@primary`, `shop:0@replica` : le keyspace est ce qui précède « : » ou « @ ».
        $keyspace = 1 === preg_match('/^([^:@]*)/', $database, $m) ? $m[1] : '';
        foreach ($tablets as $tablet) {
            if ('REPLICA' === $tablet->type && $tablet->serving && ('' === $keyspace || $tablet->keyspace === $keyspace)) {
                return self::Available;
            }
        }

        return self::Missing;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Json;

use App\Connection\TargetOpener;
use App\Core\Connection\Exception\InvalidConnectionParametersException;
use App\Core\Diagnostics\Vitess\VitessTablet;
use App\Core\Driver\Exception\UnsupportedOperationException;
use App\Core\Driver\TargetConnection;
use App\Crypto\DerivedKey;
use App\Entity\User;
use Symfony\Component\Uid\Uuid;

/**
 * Ouvre la base cible d'une connexion le temps d'une action, pour un compte et sa clé, puis la ferme quoi qu'il
 * arrive : les identifiants ne vivent pas plus longtemps que la requête. Partagé par l'interface et l'API.
 */
final readonly class TargetSession
{
    public function __construct(private TargetOpener $opener)
    {
    }

    /**
     * @template T
     *
     * @param \Closure(TargetConnection): T $action
     *
     * @return T
     */
    public function with(User $user, DerivedKey $key, Uuid $connectionId, \Closure $action, ?string $database = null, ?string $schema = null, ?string $tablet = null): mixed
    {
        if (null !== $tablet) {
            $database = $this->tabletTarget($user, $key, $connectionId, $database, $tablet);
        }
        $target = $this->opener->open($user, $key, $connectionId, $database, $schema);

        try {
            return $action($target);
        } finally {
            $target->close();
        }
    }

    /**
     * Vitess : à travers vtgate, chaque lecture du catalogue part vers UN tablet au hasard. Pour lire celui qu'on veut, le nom de base
     * qui le vise (`keyspace:shard@type|alias`) est construit ICI à partir de la liste que vtgate rend, jamais d'un texte reçu : l'alias
     * demandé doit y figurer (et, si un keyspace est nommé, être le sien).
     *
     * @throws InvalidConnectionParametersException alias mal formé ou inconnu de ce vtgate
     * @throws UnsupportedOperationException        le serveur n'est pas un vtgate (aucun tablet à choisir)
     */
    private function tabletTarget(User $user, DerivedKey $key, Uuid $connectionId, ?string $database, string $alias): string
    {
        if (!VitessTablet::isAlias($alias)) {
            throw new InvalidConnectionParametersException('The tablet is not valid.');
        }
        $keyspace = null === $database ? null : explode('@', $database, 2)[0];
        $gateway = $this->opener->open($user, $key, $connectionId, $database);
        try {
            $tablets = $gateway->diagnostics()->tablets()->tablets();
        } finally {
            $gateway->close();
        }
        foreach ($tablets as $tablet) {
            if ($tablet->alias === $alias && (null === $keyspace || '' === $keyspace || $tablet->keyspace === $keyspace)) {
                return $tablet->target();
            }
        }

        throw new InvalidConnectionParametersException('The tablet is not valid.');
    }

    public function defaultDatabase(User $user, DerivedKey $key, Uuid $connectionId): ?string
    {
        return $this->opener->defaultDatabase($user, $key, $connectionId);
    }
}

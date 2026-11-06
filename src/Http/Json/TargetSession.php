<?php

declare(strict_types=1);

namespace App\Http\Json;

use App\Connection\TargetOpener;
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
    public function with(User $user, DerivedKey $key, Uuid $connectionId, \Closure $action, ?string $database = null, ?string $schema = null): mixed
    {
        $target = $this->opener->open($user, $key, $connectionId, $database, $schema);

        try {
            return $action($target);
        } finally {
            $target->close();
        }
    }

    public function defaultDatabase(User $user, DerivedKey $key, Uuid $connectionId): ?string
    {
        return $this->opener->defaultDatabase($user, $key, $connectionId);
    }
}

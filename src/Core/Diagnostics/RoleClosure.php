<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * Fermeture transitive des rôles : un rôle accordé à un rôle donne ses droits au compte final. Les cycles (permis par certains
 * serveurs) ne bouclent pas.
 */
final class RoleClosure
{
    /**
     * @param list<string>           $roles  rôles de départ
     * @param array<string, Account> $byName comptes et rôles connus, par nom
     *
     * @return list<string> tous les rôles atteints, départ compris, dans l'ordre de découverte
     */
    public static function reachable(array $roles, array $byName): array
    {
        $seen = [];
        $queue = $roles;
        while ([] !== $queue) {
            $name = array_shift($queue);
            if (isset($seen[$name]) || !isset($byName[$name])) {
                continue;
            }
            $seen[$name] = true;
            array_push($queue, ...$byName[$name]->roles);
        }

        return array_keys($seen);
    }
}

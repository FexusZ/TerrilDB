<?php

declare(strict_types=1);

namespace App\Driver\MySql;

/**
 * information_schema désigne un compte par `'utilisateur'@'hôte'`, guillemets NON échappés : deux comptes différents pourraient
 * donner le même texte (un nom d'utilisateur qui contient `'@'`). Un texte partagé n'attribue de droits à personne.
 */
final class AccountKeys
{
    public static function of(string $user, string $host): string
    {
        return "'".$user."'@'".$host."'";
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, true>
     */
    public static function ambiguous(array $keys): array
    {
        $ambiguous = [];
        foreach (array_count_values($keys) as $key => $count) {
            if ($count > 1) {
                $ambiguous[(string) $key] = true;
            }
        }

        return $ambiguous;
    }
}

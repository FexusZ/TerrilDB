<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * Liste fermée des noms de variables dont la valeur n'est jamais montrée : mots de passe, secrets, jetons, clés privées ou de
 * chiffrement, et celles dont la VALEUR peut porter un identifiant sans que le nom le dise (chaîne de connexion de réplication
 * `primary_conninfo`, commandes d'archivage ou de restauration, `wsrep_sst_auth`, SQL de connexion `init_connect`, adresses).
 * Le nom est découpé en mots (« key_buffer_size » n'est pas une clé, « ssl_key » en est une). En second filet, une VALEUR qui a la
 * forme d'un identifiant (`password=…`, `://utilisateur:mot-de-passe@`) est masquée quel que soit le nom.
 */
final class SecretVariables
{
    private const WORDS = ['password', 'passwd', 'pwd', 'passphrase', 'secret', 'secrets', 'token', 'tokens', 'credential', 'credentials', 'keyring'];
    /** Mots qui, n'importe où dans le nom, disent qu'une valeur est une chaîne de connexion. */
    private const CONNECTION_WORDS = ['conninfo', 'dsn'];
    /** Dernier mot : la valeur est une commande, une adresse ou une authentification (`archive_command`, `redirect_url`, `wsrep_sst_auth`). */
    private const LAST_WORDS = ['command', 'cmd', 'auth', 'url', 'uri'];
    /** Du SQL ou des options exécutés à la connexion ou à la réplication : ils peuvent citer un mot de passe. */
    private const EXACT = ['init_connect', 'init_slave', 'init_replica', 'wsrep_provider_options', 'url_base'];
    private const BEFORE_KEY = ['ssl', 'tls', 'private', 'encryption', 'master', 'api', 'access', 'secret', 'signing', 'auth', 'crypto', 'jwt'];

    public static function matches(string $name): bool
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower($name), -1, \PREG_SPLIT_NO_EMPTY);
        if (false === $words) {
            return true;
        }
        if (\in_array(strtolower($name), self::EXACT, true) || \in_array(end($words), self::LAST_WORDS, true)) {
            return true;
        }
        foreach ($words as $i => $word) {
            if (\in_array($word, self::WORDS, true) || \in_array($word, self::CONNECTION_WORDS, true)) {
                return true;
            }
            if ('key' === $word && $i > 0 && \in_array($words[$i - 1], self::BEFORE_KEY, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Une valeur qui a la forme d'un identifiant : `password=…` (chaîne libpq), `pwd=`, `--password=`, ou une adresse avec
     * identifiant (`://utilisateur@` ou `://utilisateur:mot-de-passe@`).
     */
    public static function valueLooksSecret(string $value): bool
    {
        return 1 === preg_match('/(?:password|passwd|pwd|secret|token)\s*=|:\/\/[^\/\s@]+@/i', $value);
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * Liste fermée des noms de variables dont la valeur n'est jamais montrée : mots de passe, secrets, jetons, clés privées ou de
 * chiffrement. Le nom est découpé en mots (« key_buffer_size » n'est pas une clé, « ssl_key » en est une).
 */
final class SecretVariables
{
    private const WORDS = ['password', 'passwd', 'pwd', 'passphrase', 'secret', 'secrets', 'token', 'tokens', 'credential', 'credentials', 'keyring'];
    private const BEFORE_KEY = ['ssl', 'tls', 'private', 'encryption', 'master', 'api', 'access', 'secret', 'signing', 'auth', 'crypto', 'jwt'];

    public static function matches(string $name): bool
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower($name), -1, \PREG_SPLIT_NO_EMPTY);
        if (false === $words) {
            return true;
        }
        foreach ($words as $i => $word) {
            if (\in_array($word, self::WORDS, true)) {
                return true;
            }
            if ('key' === $word && $i > 0 && \in_array($words[$i - 1], self::BEFORE_KEY, true)) {
                return true;
            }
        }

        return false;
    }
}

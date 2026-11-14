<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Log;

/**
 * Le texte d'une ligne du journal d'erreurs d'un serveur peut citer des valeurs, des comptes, des adresses (`Access denied for
 * user 'alice'@'10.1.2.3'`). Avant d'entrer dans un modèle de diagnostic, il perd ses chaînes entre guillemets (y compris une
 * chaîne coupée en son milieu), ses adresses IP, ses `utilisateur@hôte` et ce qui suit un mot de passe ou un jeton, PUIS il est
 * raccourci. Défaut prudent : on masque trop plutôt que pas assez. Ce n'est pas un SQL : aucun dialecte, aucune lecture lexicale.
 */
final class LogMessage
{
    public const MAX_LENGTH = 300;

    public static function of(?string $raw): ?string
    {
        if (null === $raw) {
            return null;
        }
        $text = mb_scrub($raw, 'UTF-8');
        if ('' === trim($text)) {
            return null;
        }

        $text = (string) preg_replace(
            [
                "/(?<![\\p{L}\\p{N}])'[^']*'|\"[^\"]*\"|`[^`]*`/u",
                "/(?<![\\p{L}\\p{N}])['\"`][^'\"`]*\$/u",
                '/\b\d{1,3}(?:\.\d{1,3}){3}(?::\d+)?(?![\d.])/',
                '/[\w.+\-]+@[\w.\-]+/u',
                '/(?<!using )\b(password|passwd|pwd|secret|token)\b\s*[=:]\s*\S+/iu',
            ],
            ['?', '?', '?', '?', '$1=?'],
            $text,
        );
        $text = (string) preg_replace_callback(
            '/(?<![\w:])(?:[0-9a-f]{0,4}:){2,7}[0-9a-f]{0,4}(?![\w:])/i',
            static fn (array $m): string => str_contains($m[0], '::') || substr_count($m[0], ':') >= 7 ? '?' : $m[0],
            $text,
        );
        $text = mb_scrub($text, 'UTF-8');

        return mb_strlen($text) > self::MAX_LENGTH ? mb_substr($text, 0, self::MAX_LENGTH - 1).'…' : $text;
    }
}

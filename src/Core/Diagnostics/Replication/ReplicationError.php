<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Replication;

/**
 * Une dernière erreur de réplication. Le texte du moteur peut citer le compte de réplication, l'hôte et le port de la source,
 * voire un mot de passe : il est neutralisé (ces éléments deviennent « … ») et borné, jamais montré tel quel. Il reste ce qui
 * aide à comprendre : code, fichier de journal, table, nature de l'erreur. Rien n'est exécuté à partir de ce texte.
 */
final readonly class ReplicationError
{
    private const MAX_LENGTH = 500;

    private function __construct(
        public ?int $code,
        public string $message,
    ) {
    }

    /**
     * Null quand le moteur ne signale aucune erreur (code 0 ou absent et texte vide).
     */
    public static function from(?int $code, ?string $text): ?self
    {
        $text = trim($text ?? '');
        if ('' === $text && (null === $code || 0 === $code)) {
            return null;
        }

        return new self(0 === $code ? null : $code, self::neutralize($text));
    }

    private static function neutralize(string $text): string
    {
        $mask = '…';
        $text = (string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $text);
        $patterns = [
            // Mots de passe donnés en clair par une instruction ou un réglage.
            '/\b(?:source_password|master_password|password|passwd|pwd)\b\s*[=:]\s*\S+/i' => $mask,
            // compte@hôte, nu ou entre apostrophes.
            "/'?[\\w.\\-]+'?@'?[\\w.\\-:\\[\\]]+'?/u" => $mask,
            // Adresses IPv4 (avec port éventuel) et IPv6.
            '/\b\d{1,3}(?:\.\d{1,3}){3}(?::\d+)?\b/' => $mask,
            '/(?<![\w:])(?:[0-9a-f]{0,4}:){2,7}[0-9a-f]{0,4}(?![\w:])/i' => $mask,
            // Noms de machine : trois éléments ou plus, ou un nom suivi d'un port. Les fichiers de journal (mysql-bin.000012)
            // et les tables schéma.table n'en ont qu'un point.
            '/\b[a-z0-9][a-z0-9\-]*(?:\.[a-z0-9\-]+){2,}(?::\d+)?\b/i' => $mask,
            '/\b[a-z][a-z0-9\-]*:\d{2,5}\b/i' => $mask,
        ];
        foreach ($patterns as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > self::MAX_LENGTH ? mb_substr($text, 0, self::MAX_LENGTH).'…' : $text;
    }
}

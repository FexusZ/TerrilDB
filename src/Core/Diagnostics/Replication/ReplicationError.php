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
    private const MASK = '…';

    /**
     * @param string $message  texte sans secret NI infrastructure (compte, hôte, adresse) : le défaut
     * @param string $unmasked texte tel que le moteur le dit (réglage `TERRILDB_MASKING=0`) ; seuls les caractères de contrôle et la longueur sont traités
     */
    private function __construct(
        public ?int $code,
        public string $message,
        public string $unmasked,
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

        $cleaned = self::cleaned($text);
        $masked = self::mask(self::mask($cleaned, self::secretPatterns()), self::infrastructurePatterns());

        return new self(0 === $code ? null : $code, self::bounded($masked), self::bounded($cleaned));
    }

    private static function cleaned(string $text): string
    {
        return (string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $text);
    }

    /**
     * Masqué par défaut : mot de passe, jeton, adresse avec identifiants.
     *
     * @return list<string>
     */
    private static function secretPatterns(): array
    {
        return [
            // Adresses avec protocole : hôte, compte et mot de passe y sont tous.
            '~\b[a-z][a-z0-9+.\-]*://\S+~i',
            // Réglages et chaînes de connexion portant un secret (SOURCE_PASSWORD='…', token=…), valeur entre guillemets ou non.
            '/\b(?:[a-z]+_)*(?:password|passwd|pwd|token|secret|apikey|api_key)\b\s*[=:]\s*(?:\'[^\']*\'|"[^"]*"|\S+)/i',
            '/\bbearer\s+\S+/i',
            // Mot de passe d'une instruction citée (CREATE/ALTER USER … IDENTIFIED [WITH plugin] BY '…', ALTER ROLE … PASSWORD '…') : secret, jamais montré.
            '/\bidentified\s+(?:with\s+\S+\s+)?by\s+(?:[\\\\]?\'.*?[\\\\]?\'|"[^"]*"|\S+)/i',
            '/\bpassword\s+(?:[\\\\]?\'.*?[\\\\]?\'|"[^"]*")/i',
        ];
    }

    /**
     * Masqué par défaut, réglable : compte, hôte, adresse (de l'infrastructure, jamais une donnée de table).
     *
     * @return list<string>
     */
    private static function infrastructurePatterns(): array
    {
        return [
            // Réglages et chaînes de connexion (host=…, user=…).
            '/\b(?:[a-z]+_)*(?:host|hostaddr|user|username)\b\s*[=:]\s*(?:\'[^\']*\'|"[^"]*"|\S+)/i',
            // Un nom entre guillemets introduit par « user », « host », « on », « at »… (compte, serveur : MySQL et PostgreSQL).
            '/\b(?:user|role|host|server|source|master|primary|replica|on|at|to|from)\s+(?:name\s+)?(?:\'[^\']*\'|"[^"]*")/i',
            // compte@hôte, nu ou entre apostrophes.
            "/'?[\\w.\\-]+'?@'?[\\w.\\-:%\\[\\]]+'?/u",
            // Adresses IPv4 (avec port éventuel) et IPv6.
            '/\b\d{1,3}(?:\.\d{1,3}){3}(?::\d+)?\b/',
            '/(?<![\w:])(?:[0-9a-f]{0,4}:){2,7}[0-9a-f]{0,4}(?![\w:])/i',
            // Noms de machine : trois éléments ou plus, ou un nom suivi d'un port. Les fichiers de journal (mysql-bin.000012)
            // et les tables schéma.table n'en ont qu'un point.
            '/\b[a-z0-9][a-z0-9\-]*(?:\.[a-z0-9\-]+){2,}(?::\d+)?\b/i',
            '/\b[a-z][a-z0-9\-]*:\d{2,5}\b/i',
        ];
    }

    /**
     * @param list<string> $patterns
     */
    private static function mask(string $text, array $patterns): string
    {
        foreach ($patterns as $pattern) {
            $text = (string) preg_replace($pattern, self::MASK, $text);
        }

        return $text;
    }

    private static function bounded(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > self::MAX_LENGTH ? mb_substr($text, 0, self::MAX_LENGTH).self::MASK : $text;
    }
}

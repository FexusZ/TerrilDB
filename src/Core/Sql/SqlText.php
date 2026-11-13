<?php

declare(strict_types=1);

namespace App\Core\Sql;

use App\Core\Connection\Engine;

/**
 * Point unique pour mettre un nom issu d'une base cible dans du texte SQL que l'utilisateur lira, copiera ou lancera.
 * Un nom est une donnée hostile : un saut de ligne (LF, CR, NEL, U+2028, U+2029) fait sortir d'un commentaire `--`, un
 * guillemet non doublé d'un identifiant, un contrôle bidirectionnel réordonne l'affichage.
 *
 * - `quoteIdentifier` cite à la façon du moteur et REFUSE un nom qui n'est pas sûr ;
 * - `commentSafe` rend une version d'un texte qu'on peut écrire dans un commentaire (une ligne, sans fin de commentaire).
 */
final class SqlText
{
    public const int MAX_NAME_BYTES = 1024;
    public const int COMMENT_LENGTH = 80;

    /** Contrôles C0 et C1, DEL, séparateurs de ligne et de paragraphe, marques et isolats bidirectionnels. */
    private const string UNSAFE = '/[\p{Cc}\p{Zl}\p{Zp}\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    public static function isSafeName(string $name): bool
    {
        return '' !== $name
            && \strlen($name) <= self::MAX_NAME_BYTES
            && 1 === preg_match('//u', $name)
            && 0 === preg_match(self::UNSAFE, $name);
    }

    /**
     * @throws UnsafeSqlNameException
     */
    public static function quoteIdentifier(Engine $engine, string $name): string
    {
        if (!self::isSafeName($name)) {
            throw new UnsafeSqlNameException('This name cannot be written in SQL text.');
        }

        return match ($engine) {
            Engine::PostgreSql => '"'.str_replace('"', '""', $name).'"',
            Engine::ClickHouse => '`'.str_replace(['\\', '`'], ['\\\\', '\\`'], $name).'`',
            default => '`'.str_replace('`', '``', $name).'`',
        };
    }

    /**
     * Le texte pour un commentaire : contrôles et séparateurs de ligne remplacés par U+FFFD (octets invalides par « ? »),
     * fin de commentaire (étoile-barre) rendue inoffensive, au plus {@see self::COMMENT_LENGTH} caractères.
     */
    public static function commentSafe(string $text, int $max = self::COMMENT_LENGTH): string
    {
        $text = mb_scrub($text);
        $text = (string) preg_replace(self::UNSAFE, "\u{FFFD}", $text);
        $text = str_replace('*/', '* /', $text);
        if (mb_strlen($text) > $max) {
            $text = rtrim(mb_substr($text, 0, $max - 1)).'…';
        }

        return $text;
    }
}

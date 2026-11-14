<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

use App\Core\Guard\SqlDialect;
use App\Core\Guard\SqlScanner;

/**
 * Le texte d'une requête d'une AUTRE session peut citer des valeurs qu'on n'a pas à lire (`ALTER ROLE x PASSWORD '…'`).
 * Avant d'entrer dans un modèle de diagnostic il est donc débarrassé des octets invalides, ses littéraux (chaînes, nombres)
 * deviennent `?` et ses commentaires disparaissent, d'après la lecture lexicale du garde (`SqlScanner`, par dialecte), PUIS il
 * est raccourci. Défaut prudent : un texte que le scanner ne sait pas finir de lire (coupé par le moteur au milieu d'un
 * littéral ou d'un commentaire) n'est pas rendu du tout, `redacted` le dit.
 */
final readonly class QueryText
{
    public const MAX_LENGTH = 200;

    private function __construct(public ?string $text, public bool $redacted)
    {
    }

    public static function of(?string $raw, SqlDialect $dialect): self
    {
        if (null === $raw) {
            return new self(null, false);
        }
        $raw = mb_scrub($raw, 'UTF-8');
        if ('' === trim($raw)) {
            return new self(null, false);
        }

        $spans = [];
        $scanned = (new SqlScanner())->scan($raw, $dialect, $spans);
        if (!$scanned->complete) {
            return new self(null, true);
        }

        $masked = '';
        $position = 0;
        foreach ($spans as [$start, $end, $kind]) {
            $masked .= substr($raw, $position, $start - $position).('literal' === $kind ? '?' : ' ');
            $position = $end;
        }
        $masked .= substr($raw, $position);
        $masked = mb_scrub($masked, 'UTF-8');

        return new self(mb_strlen($masked) > self::MAX_LENGTH ? mb_substr($masked, 0, self::MAX_LENGTH - 1).'…' : $masked, false);
    }
}

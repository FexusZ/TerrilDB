<?php

declare(strict_types=1);

namespace App\Driver\MySql;

/**
 * Délai d'une requête sur Vitess (vtgate). `SET SESSION MAX_EXECUTION_TIME` y est accepté mais jamais transmis au MySQL
 * sous-jacent, et l'indication `/*+ MAX_EXECUTION_TIME(n) *\/` coupe sans erreur (SLEEP rend 1) : seule la directive de vtgate
 * `/*vt+ QUERY_TIMEOUT_MS=n *\/` interrompt la requête avec l'erreur 3024, pour une lecture comme pour une écriture.
 * Elle doit suivre le premier mot-clé (SELECT, INSERT, UPDATE, DELETE, REPLACE) ; ailleurs (SHOW, WITH…) elle n'a pas d'effet,
 * l'instruction part alors telle quelle.
 */
final class VitessTimeout
{
    private const KEYWORDS = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE'];

    /** Fin de la version annoncée par vtgate (`8.0.35-Vitess`). */
    public static function isVitessVersion(string $version): bool
    {
        return str_ends_with($version, '-Vitess');
    }

    public static function inject(string $sql, int $timeoutMs): string
    {
        $length = \strlen($sql);
        $i = 0;
        while ($i < $length) {
            $c = $sql[$i];
            if ('(' === $c || ctype_space($c)) {
                ++$i;
            } elseif ('/' === $c && '*' === ($sql[$i + 1] ?? '')) {
                $end = strpos($sql, '*/', $i + 2);
                if (false === $end) {
                    return $sql;
                }
                $i = $end + 2;
            } elseif ('#' === $c || ('-' === $c && '-' === ($sql[$i + 1] ?? '') && ctype_space($sql[$i + 2] ?? 'x'))) {
                $end = strpos($sql, "\n", $i);
                if (false === $end) {
                    return $sql;
                }
                $i = $end + 1;
            } else {
                break;
            }
        }

        if (1 !== preg_match('/\G[A-Za-z]+/', $sql, $word, 0, $i)) {
            return $sql;
        }
        $after = $i + \strlen($word[0]);
        if (!\in_array(strtoupper($word[0]), self::KEYWORDS, true) || 1 === preg_match('/\G\s*\/\*vt\+/', $sql, $m, 0, $after)) {
            return $sql;
        }

        return substr($sql, 0, $after).\sprintf(' /*vt+ QUERY_TIMEOUT_MS=%d */', $timeoutMs).substr($sql, $after);
    }
}

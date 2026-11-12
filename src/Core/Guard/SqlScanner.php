<?php

declare(strict_types=1);

namespace App\Core\Guard;

/**
 * Découpe un texte SQL en instructions et ne garde que les mots, comme le moteur les lirait.
 *
 * C'est ici que se joue le filtre lexical : tout écart entre cette lecture et celle du moteur est un angle
 * d'attaque (un second ordre caché dans un commentaire que le moteur exécute, une chaîne que le moteur ferme
 * plus tôt). D'où un traitement par dialecte : commentaires exécutables `/*!` de MySQL, `--` qui exige une
 * espace en MySQL, `#` commentaire en MySQL et ClickHouse, échappements par antislash des chaînes MySQL et
 * ClickHouse (et de leurs identifiants pour ClickHouse) et des chaînes `E'…'` de PostgreSQL, guillemets dollar
 * de PostgreSQL et ClickHouse, commentaires imbriqués de PostgreSQL.
 *
 * Le filtre n'est jamais seul : la transaction en lecture seule du moteur fait foi.
 */
final class SqlScanner
{
    public function scan(string $sql, SqlDialect $dialect): ScannedSql
    {
        $mysql = SqlDialect::MySql === $dialect;
        $clickhouse = SqlDialect::ClickHouse === $dialect;
        // MySQL et ClickHouse : antislash dans les chaînes, « # » commentaire. Le guillemet double est une chaîne en MySQL,
        // un identifiant ailleurs. Les guillemets dollar existent en PostgreSQL et en ClickHouse.
        $backslash = $mysql || $clickhouse;
        $dollarQuotes = !$mysql;
        $length = \strlen($sql);
        $statements = [];
        $words = [];
        $depths = [];
        $depth = 0;
        $complete = true;
        $called = [];
        $callFlags = [];
        $groups = [];
        $openGroups = [0];
        $lastGroup = 0;
        $executableComment = false;
        $separators = [];
        $separatorInExecutableComment = false;
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($executableComment && '*' === $char && '/' === $next) {
                $executableComment = false;
                $i += 2;
            } elseif (ctype_space($char)) {
                ++$i;
            } elseif (';' === $char) {
                $separators[] = $i;
                $separatorInExecutableComment = $separatorInExecutableComment || $executableComment;
                $this->flush($words, $depths, $called, $callFlags, $groups, $statements);
                $depth = 0;
                $openGroups = [0];
                $lastGroup = 0;
                ++$i;
            } elseif ('(' === $char) {
                ++$depth;
                $openGroups[] = ++$lastGroup;
                ++$i;
            } elseif (')' === $char) {
                $depth = max(0, $depth - 1);
                if (\count($openGroups) > 1) {
                    array_pop($openGroups);
                }
                ++$i;
            } elseif ('-' === $char && '-' === $next && (!$mysql || $this->endsOrSpaces($sql, $i + 2))) {
                $i = $this->endOfLine($sql, $i);
            } elseif ($backslash && '#' === $char) {
                $i = $this->endOfLine($sql, $i);
            } elseif ('/' === $char && '*' === $next) {
                if ($mysql && ('!' === ($sql[$i + 2] ?? '') || ('M' === ($sql[$i + 2] ?? '') && '!' === ($sql[$i + 3] ?? '')))) {
                    // MySQL et MariaDB exécutent le contenu de ces commentaires.
                    $executableComment = true;
                    $i += 'M' === $sql[$i + 2] ? 4 : 3;
                    while ($i < $length && ctype_digit($sql[$i])) {
                        ++$i;
                    }
                } else {
                    $i = $this->endOfBlockComment($sql, $i, SqlDialect::PostgreSql === $dialect);
                    if (null === $i) {
                        $complete = false;
                        break;
                    }
                }
            } elseif ("'" === $char || ($mysql && '"' === $char)) {
                $start = $i;
                $i = $this->endOfQuoted($sql, $i, $char, $backslash);
                if (null === $i) {
                    $complete = false;
                    break;
                }
                // Le guillemet double est un identifiant en MySQL avec ANSI_QUOTES : par prudence, suivi d'une parenthèse il est un appel.
                if ('"' === $char && '(' === $this->nextNonSpace($sql, $i, $mysql, $backslash)) {
                    $called[] = $this->quotedName($sql, $start, $i, $backslash);
                }
            } elseif (($backslash && '`' === $char) || (!$mysql && '"' === $char)) {
                $start = $i;
                $i = $this->endOfQuoted($sql, $i, $char, $clickhouse);
                if (null === $i) {
                    $complete = false;
                    break;
                }
                // Un identifiant entre guillemets suivi d'une parenthèse est un appel de fonction (le dernier élément d'un nom qualifié
                // aussi) : son nom, déquoté, rejoint les appels sans devenir un mot (une colonne « select » n'est pas le verbe).
                if ('(' === $this->nextNonSpace($sql, $i, $mysql, $backslash)) {
                    $called[] = $this->quotedName($sql, $start, $i, $clickhouse);
                }
            } elseif ($dollarQuotes && '$' === $char && 1 === preg_match('/\G\$([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)?\$/', $sql, $tag, 0, $i)) {
                $end = strpos($sql, $tag[0], $i + \strlen($tag[0]));
                if (false === $end) {
                    $complete = false;
                    break;
                }
                $i = $end + \strlen($tag[0]);
            } elseif (ctype_alpha($char) || '_' === $char || \ord($char) >= 0x80) {
                $start = $i;
                while ($i < $length && (ctype_alnum($sql[$i]) || '_' === $sql[$i] || '$' === $sql[$i] || \ord($sql[$i]) >= 0x80)) {
                    ++$i;
                }
                $word = substr($sql, $start, $i - $start);
                $prefix = 1 === \strlen($word) && "'" === ($sql[$i] ?? '') ? strtoupper($word) : '';
                if ('' !== $prefix && \in_array($prefix, ['E', 'B', 'X', 'N'], true)) {
                    // Préfixe de chaîne (E'…', B'…', X'…', N'…'), pas un mot ; seul E'…' accepte l'antislash en PostgreSQL.
                    $i = $this->endOfQuoted($sql, $i, "'", $backslash || 'E' === $prefix);
                    if (null === $i) {
                        $complete = false;
                        break;
                    }
                } else {
                    $words[] = strtoupper($word);
                    $depths[] = $depth;
                    $groups[] = $openGroups[array_key_last($openGroups)];
                    $isCall = '(' === $this->nextNonSpace($sql, $i, $mysql, $backslash);
                    $callFlags[] = $isCall;
                    if ($isCall) {
                        $called[] = strtoupper($word);
                    }
                }
            } elseif (ctype_digit($char)) {
                while ($i < $length && (ctype_alnum($sql[$i]) || '_' === $sql[$i] || '.' === $sql[$i])) {
                    ++$i;
                }
            } else {
                ++$i;
            }
        }

        $this->flush($words, $depths, $called, $callFlags, $groups, $statements);

        return new ScannedSql($statements, $complete, $separators, $separatorInExecutableComment);
    }

    /**
     * @param list<string>           $words
     * @param list<int>              $depths
     * @param list<string>           $called
     * @param list<bool>             $callFlags
     * @param list<int>              $groups
     * @param list<ScannedStatement> $statements
     */
    private function flush(array &$words, array &$depths, array &$called, array &$callFlags, array &$groups, array &$statements): void
    {
        if ([] !== $words) {
            $statements[] = new ScannedStatement($words, $depths, $called, $callFlags, $groups);
        }
        $words = [];
        $depths = [];
        $called = [];
        $callFlags = [];
        $groups = [];
    }

    /**
     * Le premier caractère utile après `$from` : espaces et commentaires sautés (« nom /* x *\/ ( » est un appel pour le
     * moteur). Un commentaire exécutable MySQL (`/*!`) rend « ( » : son contenu peut être l'ouverture de l'appel.
     */
    private function nextNonSpace(string $sql, int $from, bool $mysql, bool $hashComments): string
    {
        $length = \strlen($sql);
        while ($from < $length) {
            $char = $sql[$from];
            $next = $sql[$from + 1] ?? '';
            if (ctype_space($char)) {
                ++$from;
            } elseif ('/' === $char && '*' === $next) {
                if ($mysql && ('!' === ($sql[$from + 2] ?? '') || ('M' === ($sql[$from + 2] ?? '') && '!' === ($sql[$from + 3] ?? '')))) {
                    return '(';
                }
                $end = strpos($sql, '*/', $from + 2);
                if (false === $end) {
                    return '';
                }
                $from = $end + 2;
            } elseif (('-' === $char && '-' === $next && (!$mysql || $this->endsOrSpaces($sql, $from + 2))) || ($hashComments && '#' === $char)) {
                $from = $this->endOfLine($sql, $from);
            } else {
                return $char;
            }
        }

        return '';
    }

    /**
     * Le nom d'un identifiant entre guillemets, sans eux, guillemets doublés et échappements lus, en majuscules.
     */
    private function quotedName(string $sql, int $start, int $end, bool $backslashEscapes): string
    {
        $quote = $sql[$start];
        $name = substr($sql, $start + 1, $end - $start - 2);
        $name = str_replace($quote.$quote, $quote, $name);
        if ($backslashEscapes) {
            $name = (string) preg_replace('/\\\\(.)/s', '$1', $name);
        }

        return strtoupper($name);
    }

    private function endsOrSpaces(string $sql, int $position): bool
    {
        return $position >= \strlen($sql) || ctype_space($sql[$position]) || ctype_cntrl($sql[$position]);
    }

    private function endOfLine(string $sql, int $from): int
    {
        $end = strpos($sql, "\n", $from);

        return false === $end ? \strlen($sql) : $end + 1;
    }

    /**
     * @return int|null la position après la fin du commentaire, null s'il n'est pas refermé
     */
    private function endOfBlockComment(string $sql, int $from, bool $nested): ?int
    {
        $depth = 1;
        $i = $from + 2;
        $length = \strlen($sql);
        while ($i < $length) {
            if ('*' === $sql[$i] && '/' === ($sql[$i + 1] ?? '')) {
                --$depth;
                $i += 2;
                if (0 === $depth) {
                    return $i;
                }
            } elseif ($nested && '/' === $sql[$i] && '*' === ($sql[$i + 1] ?? '')) {
                ++$depth;
                $i += 2;
            } else {
                ++$i;
            }
        }

        return null;
    }

    /**
     * @return int|null la position après le guillemet fermant, null si la chaîne n'est pas refermée
     */
    private function endOfQuoted(string $sql, int $from, string $quote, bool $backslashEscapes): ?int
    {
        $length = \strlen($sql);
        $i = $from + 1;
        while ($i < $length) {
            $char = $sql[$i];
            if ($backslashEscapes && '\\' === $char) {
                $i += 2;
            } elseif ($char === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) {
                    $i += 2;
                } else {
                    return $i + 1;
                }
            } else {
                ++$i;
            }
        }

        return null;
    }
}

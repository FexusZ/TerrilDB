<?php

declare(strict_types=1);

namespace App\Driver\ClickHouse;

/**
 * Découpe l'expression d'une clé ClickHouse (« toDate(ts), cityHash64(a, b), id ») en colonnes ou expressions,
 * sans couper dans les parenthèses ni dans les chaînes.
 */
final class KeyExpression
{
    /**
     * @return list<string>
     */
    public static function split(string $expression): array
    {
        $expression = trim($expression);
        if (str_starts_with($expression, '(') && str_ends_with($expression, ')') && self::wrapsEverything($expression)) {
            $expression = substr($expression, 1, -1);
        }

        $parts = [];
        $current = '';
        $depth = 0;
        $quote = null;
        for ($i = 0, $length = \strlen($expression); $i < $length; ++$i) {
            $char = $expression[$i];
            if (null !== $quote) {
                $current .= $char;
                if ('\\' === $char && $i + 1 < $length) {
                    $current .= $expression[++$i];
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif ("'" === $char || '"' === $char || '`' === $char) {
                $quote = $char;
                $current .= $char;
            } elseif ('(' === $char || '[' === $char) {
                ++$depth;
                $current .= $char;
            } elseif (')' === $char || ']' === $char) {
                --$depth;
                $current .= $char;
            } elseif (',' === $char && 0 === $depth) {
                $parts[] = trim($current);
                $current = '';
            } else {
                $current .= $char;
            }
        }
        $parts[] = trim($current);

        return array_values(array_filter($parts, static fn (string $part): bool => '' !== $part));
    }

    /**
     * « (a, b) » enveloppe tout ; « (a), (b) » non.
     */
    private static function wrapsEverything(string $expression): bool
    {
        $depth = 0;
        $quote = null;
        $last = \strlen($expression) - 1;
        for ($i = 0; $i <= $last; ++$i) {
            $char = $expression[$i];
            if (null !== $quote) {
                if ('\\' === $char) {
                    ++$i;
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif ("'" === $char || '"' === $char || '`' === $char) {
                $quote = $char;
            } elseif ('(' === $char) {
                ++$depth;
            } elseif (')' === $char) {
                --$depth;
                if (0 === $depth && $i < $last) {
                    return false;
                }
            }
        }

        return true;
    }
}

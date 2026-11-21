<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Vitess;

/**
 * Les tables d'un keyspace qui nourrissent un flux VReplication (Materialize, MoveTables…), lues dans le champ `source` de
 * `_vt.vreplication` (un BinlogSource en texte : `keyspace:"k" shard:"0" filter:{rules:{match:"cible" filter:"select … from source"}}`).
 * Un ALTER sur une table source n'est pas propagé au flux : c'est ce qu'on veut dire AVANT, sans jamais le refuser.
 *
 * Lecture lexicale et prudente : un nom en trop donne un avertissement de trop, jamais un blocage.
 */
final readonly class StreamSource
{
    /**
     * @param list<string> $tables   noms minuscules lus dans les `FROM` / `JOIN`, ou le `match` d'une règle sans `SELECT`
     * @param list<string> $patterns expressions (`/…/`) des règles dont le nom n'est pas fixe
     */
    private function __construct(public string $keyspace, private array $tables, private array $patterns)
    {
    }

    public static function parse(string $source): ?self
    {
        if (1 !== preg_match('/\bkeyspace:"((?:[^"\\\\]|\\\\.)*)"/', $source, $keyspace) || '' === $keyspace[1]) {
            return null;
        }
        $tables = [];
        $patterns = [];
        if (false !== preg_match_all('/\bmatch:"((?:[^"\\\\]|\\\\.)*)"(?:\s+filter:"((?:[^"\\\\]|\\\\.)*)")?/', $source, $rules, \PREG_SET_ORDER)) {
            foreach ($rules as $rule) {
                $match = stripcslashes($rule[1]);
                $filter = isset($rule[2]) ? stripcslashes($rule[2]) : '';
                if ('' !== $filter) {
                    array_push($tables, ...self::tablesOf($filter));
                } elseif (str_starts_with($match, '/')) {
                    $patterns[] = $match;
                } elseif ('' !== $match) {
                    $tables[] = strtolower($match);
                }
            }
        }

        return new self($keyspace[1], array_values(array_unique($tables)), $patterns);
    }

    public function feeds(string $table): bool
    {
        $table = strtolower($table);
        if (\in_array($table, $this->tables, true)) {
            return true;
        }
        foreach ($this->patterns as $pattern) {
            // `/expression/` : Vitess ancre l'expression, un nom ne la satisfait que tout entier.
            $expression = '~^(?:'.str_replace('~', '\~', trim($pattern, '/')).')$~i';
            if (1 === @preg_match($expression, $table)) {
                return true;
            }
        }

        return false;
    }

    /**
     * L'instruction nomme-t-elle une table source ? Chaînes et commentaires ne comptent pas ; un nom plus long qui la contient non plus.
     */
    public function touchedBy(string $sql): bool
    {
        $bare = preg_replace(['~/\\*.*?\\*/~s', '~--[^\\n]*~', "~'(?:[^'\\\\]|\\\\.|'')*'~s", '~"(?:[^"\\\\]|\\\\.|"")*"~s'], ' ', $sql);
        if (!\is_string($bare) || false === preg_match_all('/`([^`]+)`|([A-Za-z_][A-Za-z0-9_$]*)/', $bare, $found, \PREG_SET_ORDER)) {
            return false;
        }
        foreach ($found as $word) {
            if ($this->feeds('' !== ($word[1] ?? '') ? $word[1] : ($word[2] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tout mot qui suit le premier `FROM` : tables, alias, colonnes de jointure. Lire les jointures et les listes à virgules mot à mot
     * serait plus fin et plus fragile ; ici un mot en trop donne au pire un avertissement de trop.
     *
     * @return list<string>
     */
    private static function tablesOf(string $select): array
    {
        if (1 !== preg_match('/\bfrom\b(.*)$/is', $select, $tail)) {
            return [];
        }
        $words = [];
        if (false !== preg_match_all('/`([^`]+)`|([A-Za-z_][A-Za-z0-9_$]*)/', $tail[1], $found, \PREG_SET_ORDER)) {
            foreach ($found as $word) {
                $words[] = strtolower('' !== ($word[1] ?? '') ? $word[1] : ($word[2] ?? ''));
            }
        }

        return $words;
    }
}

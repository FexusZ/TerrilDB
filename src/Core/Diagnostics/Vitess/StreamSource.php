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
     * @param list<string>                $tables   noms minuscules lus dans les `FROM` / `JOIN`, ou le `match` d'une règle sans `SELECT`
     * @param list<string>                $patterns expressions (`/…/`) des règles dont le nom n'est pas fixe
     * @param array<string, list<string>> $targets  table cible (minuscules) => tables sources de sa règle
     */
    private function __construct(public string $keyspace, private array $tables, private array $patterns, public string $workflow = '', private array $targets = [])
    {
    }

    public static function parse(string $source, string $workflow = ''): ?self
    {
        if (1 !== preg_match('/\bkeyspace:"((?:[^"\\\\]|\\\\.)*)"/', $source, $keyspace) || '' === $keyspace[1]) {
            return null;
        }
        $tables = [];
        $patterns = [];
        $targets = [];
        if (false !== preg_match_all('/\bmatch:"((?:[^"\\\\]|\\\\.)*)"(?:\s+filter:"((?:[^"\\\\]|\\\\.)*)")?/', $source, $rules, \PREG_SET_ORDER)) {
            foreach ($rules as $rule) {
                $match = stripcslashes($rule[1]);
                $filter = isset($rule[2]) ? stripcslashes($rule[2]) : '';
                if ('' !== $filter) {
                    $read = self::tablesOf($filter);
                    array_push($tables, ...$read);
                    if ('' !== $match && !str_starts_with($match, '/')) {
                        $targets[strtolower($match)] = array_values(array_unique([...($targets[strtolower($match)] ?? []), ...$read]));
                    }
                } elseif (str_starts_with($match, '/')) {
                    $patterns[] = $match;
                } elseif ('' !== $match) {
                    $tables[] = strtolower($match);
                    $targets[strtolower($match)] = [strtolower($match)];
                }
            }
        }

        return new self($keyspace[1], array_values(array_unique($tables)), $patterns, $workflow, $targets);
    }

    /**
     * Les tables que ce flux remplit à partir de `$table` (nom fixe seulement : une règle par expression ne nomme rien). Un MoveTables
     * garde le même nom dans l'autre keyspace.
     *
     * @return list<string>
     */
    public function targetsOf(string $table): array
    {
        $table = strtolower($table);
        $found = [];
        foreach ($this->targets as $target => $sources) {
            if (\in_array($table, $sources, true)) {
                $found[] = (string) $target;
            }
        }

        return $found;
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

    public function hitFor(string $table): ?StreamHit
    {
        return $this->feeds($table) ? new StreamHit($this->workflow, $table, $this->targetsOf($table)) : null;
    }

    /**
     * Les tables source que l'instruction nomme, une fois chacune. Chaînes et commentaires ne comptent pas ; un nom plus long qui la contient non plus.
     *
     * @return list<StreamHit>
     */
    public function hitsIn(string $sql): array
    {
        $bare = preg_replace(['~/\\*.*?\\*/~s', '~--[^\\n]*~', "~'(?:[^'\\\\]|\\\\.|'')*'~s", '~"(?:[^"\\\\]|\\\\.|"")*"~s'], ' ', $sql);
        if (!\is_string($bare) || false === preg_match_all('/`([^`]+)`|([A-Za-z_][A-Za-z0-9_$]*)/', $bare, $found, \PREG_SET_ORDER)) {
            return [];
        }
        $hits = [];
        foreach ($found as $word) {
            $name = '' !== ($word[1] ?? '') ? $word[1] : ($word[2] ?? '');
            $hit = isset($hits[strtolower($name)]) ? null : $this->hitFor($name);
            if (null !== $hit) {
                $hits[strtolower($name)] = $hit;
            }
        }

        return array_values($hits);
    }

    public function touchedBy(string $sql): bool
    {
        return [] !== $this->hitsIn($sql);
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

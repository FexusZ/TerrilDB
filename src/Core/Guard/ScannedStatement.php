<?php

declare(strict_types=1);

namespace App\Core\Guard;

/**
 * Une instruction réduite à ses mots-clés et identifiants, en majuscules, dans l'ordre : commentaires,
 * chaînes et identifiants entre guillemets ont disparu.
 */
final readonly class ScannedStatement
{
    /** Mots qui ouvrent une instruction : la tête d'un WITH est le premier d'entre eux hors de toute parenthèse. */
    private const STATEMENT_VERBS = [
        'SELECT', 'VALUES', 'TABLE', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'MERGE', 'CALL', 'DO',
        'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'EXISTS',
        'CREATE', 'COMMENT', 'ANALYZE', 'OPTIMIZE', 'VACUUM', 'REINDEX', 'REFRESH', 'CLUSTER', 'ATTACH',
        'DROP', 'TRUNCATE', 'ALTER', 'RENAME', 'GRANT', 'REVOKE', 'DETACH', 'EXCHANGE',
        'LOAD', 'COPY', 'SET', 'BEGIN', 'COMMIT', 'ROLLBACK', 'USE', 'LOCK', 'UNLOCK', 'FLUSH', 'KILL', 'SHUTDOWN',
    ];

    private const EXPLAIN_OPTIONS = ['ANALYZE', 'ANALYSE', 'VERBOSE', 'FORMAT', 'JSON', 'TREE', 'TRADITIONAL', 'EXTENDED', 'PARTITIONS'];

    /**
     * @param non-empty-list<string> $words
     * @param list<int>              $depths    profondeur de parenthèses de chaque mot (0 = niveau de l'instruction)
     * @param list<string>           $called    noms directement suivis d'une parenthèse ouvrante (appels de fonction), identifiants entre guillemets compris
     * @param list<bool>             $callFlags pour chaque mot : directement suivi d'une parenthèse ouvrante
     * @param list<int>              $groups    pour chaque mot : la paire de parenthèses qui le contient (0 = hors de toute paire, chaque paire a son numéro)
     */
    public function __construct(public array $words, private array $depths, private array $called = [], private array $callFlags = [], private array $groups = [])
    {
        if (\count($depths) !== \count($words)) {
            throw new \InvalidArgumentException('Each word needs a depth.');
        }
    }

    /**
     * Le mot à cette position est écrit comme un appel de fonction (« replace (a, b) »), pas comme un mot-clé.
     */
    public function isCallAt(int $index): bool
    {
        return $this->callFlags[$index] ?? false;
    }

    /**
     * Le mot à cette position est un verbe d'écriture qui ouvre une instruction (ou une expression de table qui écrit) :
     * ni la fonction « replace(…) », ni « FOR UPDATE », « ON DUPLICATE KEY UPDATE », « DO UPDATE » ou « ON DELETE CASCADE ».
     */
    public function isRowWriteAt(int $index): bool
    {
        $word = $this->words[$index];
        if (!\in_array($word, ['INSERT', 'UPDATE', 'DELETE', 'MERGE', 'REPLACE'], true) || $this->isCallAt($index)) {
            return false;
        }
        if (\in_array($word, ['UPDATE', 'DELETE'], true) && $index > 0) {
            $sameGroup = ($this->depths[$index - 1] ?? -1) === $this->depths[$index];
            if ($sameGroup && \in_array($this->words[$index - 1], ['ON', 'DO', 'KEY', 'FOR'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Un UPDATE ou un DELETE (à n'importe quel niveau : tête, expression de table, MERGE…) sans WHERE dans sa propre
     * paire de parenthèses : le WHERE d'une sous-requête ou d'une autre expression ne limite pas les lignes touchées.
     */
    public function hasUnboundedRowChange(): bool
    {
        foreach ($this->words as $index => $word) {
            if (!\in_array($word, ['UPDATE', 'DELETE'], true) || !$this->isRowWriteAt($index)) {
                continue;
            }
            $group = $this->groups[$index] ?? $this->depths[$index];
            $bounded = false;
            for ($j = $index + 1, $count = \count($this->words); $j < $count && !$bounded; ++$j) {
                $bounded = 'WHERE' === $this->words[$j] && ($this->groups[$j] ?? $this->depths[$j]) === $group;
            }
            if (!$bounded) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pour une instruction qui commence par WITH : le mot qui ouvre l'instruction après les expressions de table
     * (SELECT, UPDATE, DROP…), ou null s'il n'y en a pas. Les noms d'expression (suivis de AS ou d'une liste de colonnes)
     * ne comptent pas, et ce qui est entre parenthèses est le corps des expressions.
     */
    public function withHead(): ?string
    {
        return 'WITH' === $this->words[0] ? $this->headAfterWith(0) : null;
    }

    private function headAfterWith(int $with): ?string
    {
        for ($i = $with + 1, $count = \count($this->words); $i < $count; ++$i) {
            $word = $this->words[$i];
            if (0 !== $this->depths[$i] || !\in_array($word, self::STATEMENT_VERBS, true)) {
                continue;
            }
            $named = 'AS' === ($this->words[$i + 1] ?? '') || ($this->isCallAt($i) && !\in_array($word, ['SELECT', 'VALUES'], true));
            if (!$named) {
                return $word;
            }
        }

        return null;
    }

    /**
     * L'instruction que le moteur EXÉCUTE vraiment, pour la juger comme telle : la tête d'un WITH ; derrière
     * EXPLAIN ANALYZE (ou EXPLAIN (ANALYZE …)), l'instruction expliquée, qui est jouée pour de bon ; derrière ANALYZE
     * (MariaDB), une écriture qui suit. Un EXPLAIN simple n'exécute rien (EXPLAIN) ; ANALYZE TABLE reste une maintenance.
     * Null : on ne sait pas dire ce qui sera exécuté (refus).
     */
    public function executedVerb(): ?string
    {
        $first = $this->words[0];
        if ('WITH' === $first) {
            return $this->headAfterWith(0);
        }
        if ('EXPLAIN' === $first && ($this->has('ANALYZE') || $this->has('ANALYSE'))) {
            $inner = $this->innerStatementIndex(1);

            return null === $inner ? null : ('WITH' === $this->words[$inner] ? $this->headAfterWith($inner) : $this->words[$inner]);
        }
        if ('ANALYZE' === $first) {
            $inner = 'TABLE' === ($this->words[1] ?? '') ? null : $this->innerStatementIndex(1);
            $verb = null === $inner ? null : $this->words[$inner];
            if (null !== $inner && 'WITH' === $verb) {
                $verb = $this->headAfterWith($inner);
            }

            return \in_array($verb, ['INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'MERGE'], true) ? $verb : 'ANALYZE';
        }

        return $first;
    }

    /**
     * Le mot qui ouvre l'instruction expliquée, après les options (ANALYZE, VERBOSE, FORMAT=JSON…) ; null si le premier mot
     * qui n'est pas une option n'est pas un verbe connu (EXECUTE, DECLARE… : on ne sait pas juger ce qui sera joué).
     */
    private function innerStatementIndex(int $from): ?int
    {
        for ($i = $from, $count = \count($this->words); $i < $count; ++$i) {
            if (0 !== $this->depths[$i] || \in_array($this->words[$i], self::EXPLAIN_OPTIONS, true)) {
                continue;
            }

            return 'WITH' === $this->words[$i] || \in_array($this->words[$i], self::STATEMENT_VERBS, true) ? $i : null;
        }

        return null;
    }

    public function first(): string
    {
        return $this->words[0];
    }

    /**
     * Le mot est écrit comme un appel de fonction (« FILE ('x') »), pas comme un nom de colonne.
     */
    public function calls(string $word): bool
    {
        return \in_array($word, $this->called, true);
    }

    /**
     * Les noms écrits comme un appel de fonction, y compris ceux entre guillemets (déquotés, en majuscules) qui ne sont pas des mots.
     *
     * @return list<string>
     */
    public function calledNames(): array
    {
        return $this->called;
    }

    public function has(string $word): bool
    {
        return \in_array($word, $this->words, true);
    }

    /**
     * Présent hors de toute parenthèse : un WHERE d'une sous-requête ne protège pas l'UPDATE qui l'entoure.
     */
    public function hasTopLevel(string $word): bool
    {
        foreach ($this->words as $index => $candidate) {
            if ($candidate === $word && 0 === $this->depths[$index]) {
                return true;
            }
        }

        return false;
    }

    public function hasSequence(string ...$sequence): bool
    {
        $length = \count($sequence);
        for ($i = 0, $max = \count($this->words) - $length; $i <= $max; ++$i) {
            if (\array_slice($this->words, $i, $length) === array_values($sequence)) {
                return true;
            }
        }

        return false;
    }
}

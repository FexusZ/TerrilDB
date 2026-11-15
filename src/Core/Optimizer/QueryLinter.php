<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

use App\Core\Connection\Engine;
use App\Core\Guard\SqlDialect;
use App\Core\Guard\SqlScanner;

/**
 * Lit le TEXTE d'une lecture et signale des motifs connus pour ralentir une requête ou la rendre fragile : fonction ou
 * calcul sur une colonne dans une condition, joker en tête d'un LIKE, SELECT *, grand OFFSET, NOT IN (sous-requête),
 * verrou de lignes, WITH RECURSIVE. Rien n'est exécuté ni réécrit, et un avertissement n'empêche jamais rien.
 *
 * Choix de prudence : zéro faux positif plutôt que couverture. Les chaînes et commentaires sont lus par SqlScanner (un
 * motif écrit dans un texte ne compte pas) ; un texte incomplet, à plusieurs instructions ou qui n'est pas un SELECT
 * (ou un WITH qui mène à un SELECT) ne donne rien ; une condition n'est examinée que si elle COMMENCE par la
 * fonction ou la colonne (le reste n'est pas deviné). Sans connaissance du schéma, on ne dit jamais « cet index ne
 * sert pas » mais « s'il y en a un, il ne servira pas ». ClickHouse (index de saut, pas de verrous) ne garde que les
 * motifs qui lui parlent.
 */
final class QueryLinter
{
    private const LARGE_OFFSET = 10_000;

    /** Bornes de lecture : au-delà, le texte n'est pas examiné (le coût des conditions imbriquées croît avec la profondeur). */
    private const MAX_TOKENS = 20_000;
    private const MAX_DEPTH = 32;

    /** Fonctions qui, appliquées à une colonne, empêchent d'utiliser l'index sur cette colonne. */
    private const FUNCTIONS = [
        'YEAR', 'MONTH', 'DAY', 'DAYOFMONTH', 'DAYOFWEEK', 'DAYOFYEAR', 'WEEK', 'HOUR', 'MINUTE', 'DATE', 'TIME',
        'DATE_FORMAT', 'DATE_TRUNC', 'DATE_PART', 'TO_CHAR', 'TO_DATE', 'EXTRACT',
        'LOWER', 'UPPER', 'LCASE', 'UCASE', 'TRIM', 'LTRIM', 'RTRIM', 'SUBSTR', 'SUBSTRING', 'LEFT', 'RIGHT',
        'LENGTH', 'CHAR_LENGTH', 'CONCAT', 'COALESCE', 'IFNULL', 'NVL', 'ABS', 'ROUND', 'FLOOR', 'CEIL', 'CEILING', 'CAST', 'MD5',
    ];

    /** Mots qui ne sont pas un nom de colonne. */
    private const NOT_COLUMNS = [
        'FROM', 'FOR', 'AS', 'AND', 'OR', 'NOT', 'IN', 'IS', 'NULL', 'TRUE', 'FALSE', 'INTERVAL', 'CASE', 'WHEN', 'THEN', 'ELSE', 'END',
        'DISTINCT', 'YEAR', 'MONTH', 'DAY', 'HOUR', 'MINUTE', 'SECOND', 'WEEK', 'QUARTER', 'EPOCH', 'DATE', 'TIME', 'TIMESTAMP',
        'DATETIME', 'SIGNED', 'UNSIGNED', 'CHAR', 'VARCHAR', 'TEXT', 'INT', 'INTEGER', 'BIGINT', 'DECIMAL', 'NUMERIC', 'FLOAT', 'DOUBLE',
        'BOOLEAN', 'BOTH', 'LEADING', 'TRAILING', 'USING', 'ASC', 'DESC', 'CURRENT_DATE', 'CURRENT_TIME', 'CURRENT_TIMESTAMP',
        'LOCALTIME', 'LOCALTIMESTAMP', 'LIKE', 'ILIKE', 'BETWEEN', 'EXISTS', 'SELECT', 'ANY', 'ALL', 'ESCAPE',
    ];

    /** Mots qui ferment une condition (le mot suivi d'une parenthèse est un appel de fonction, pas un de ceux-là). */
    private const CLAUSE_ENDS = [
        'GROUP', 'ORDER', 'HAVING', 'LIMIT', 'UNION', 'INTERSECT', 'EXCEPT', 'WINDOW', 'FOR', 'OFFSET', 'FETCH', 'JOIN', 'INNER',
        'LEFT', 'RIGHT', 'FULL', 'CROSS', 'NATURAL', 'SELECT', 'FROM', 'WITH', 'RETURNING', 'USING', 'STRAIGHT_JOIN', 'LOCK',
    ];

    /**
     * @return list<LintFinding>
     */
    public function lint(Engine $engine, string $sql): array
    {
        if (!$engine->speaksSql()) {
            return [];
        }
        $dialect = SqlDialect::forEngine($engine);
        if (SqlDialect::MySql === $dialect && (str_contains($sql, '/*!') || str_contains($sql, '/*M!'))) {
            return [];
        }

        $spans = [];
        $scanned = (new SqlScanner())->scan($sql, $dialect, $spans);
        if (!$scanned->complete || 1 !== \count($scanned->statements)) {
            return [];
        }
        $statement = $scanned->statements[0];
        if (!\in_array($statement->first(), ['SELECT', 'WITH'], true) || ('WITH' === $statement->first() && 'SELECT' !== $statement->withHead())) {
            return [];
        }

        $tokens = $this->tokens($sql, $dialect, $spans);
        if (null === $tokens || !$this->withinBounds($tokens)) {
            return [];
        }

        $clickhouse = Engine::ClickHouse === $engine;
        $findings = [];
        if (!$clickhouse) {
            foreach ($this->conditions($tokens) as [$from, $to]) {
                $finding = $this->checkCondition(\array_slice($tokens, $from, $to - $from), $dialect);
                if (null !== $finding) {
                    $findings[] = $finding;
                }
            }
            array_push($findings, ...$this->notIn($tokens), ...$this->locks($tokens), ...$this->recursive($tokens));
        }
        array_push($findings, ...$this->selectStar($tokens), ...$this->largeOffset($tokens, SqlDialect::MySql === $dialect));

        $seen = [];

        return array_values(array_filter($findings, static function (LintFinding $f) use (&$seen): bool {
            $key = $f->rule->value."\0".implode(',', $f->columns);
            if (isset($seen[$key])) {
                return false;
            }

            return $seen[$key] = true;
        }));
    }

    /**
     * @param list<LintToken> $tokens
     */
    private function withinBounds(array $tokens): bool
    {
        if (\count($tokens) > self::MAX_TOKENS) {
            return false;
        }
        $depth = 0;
        foreach ($tokens as $token) {
            if ($token->isPunct('(') && ++$depth > self::MAX_DEPTH) {
                return false;
            }
            if ($token->isPunct(')')) {
                $depth = max(0, $depth - 1);
            }
        }

        return true;
    }

    /**
     * @param list<array{int, int, string}> $spans
     *
     * @return list<LintToken>|null null si le texte ne se lit pas sûrement
     */
    private function tokens(string $sql, SqlDialect $dialect, array $spans): ?array
    {
        $length = \strlen($sql);
        $tokens = [];
        $spanIndex = 0;
        $i = 0;
        while ($i < $length) {
            while (isset($spans[$spanIndex]) && $spans[$spanIndex][0] < $i) {
                ++$spanIndex;
            }
            if (isset($spans[$spanIndex]) && $spans[$spanIndex][0] === $i) {
                [$start, $end, $type] = $spans[$spanIndex];
                $i = $end;
                if ('literal' === $type) {
                    $raw = substr($sql, $start, $end - $start);
                    if (ctype_digit($raw[0])) {
                        $tokens[] = new LintToken(LintToken::NUMBER, $raw);
                    } else {
                        $quote = strcspn($raw, '\'"$');
                        $tokens[] = new LintToken(LintToken::STRING, '$' === ($raw[$quote] ?? '') ? '' : substr($raw, $quote + 1, -1));
                    }
                }

                continue;
            }
            $char = $sql[$i];
            if (ctype_space($char)) {
                ++$i;
            } elseif (ctype_alpha($char) || '_' === $char || \ord($char) >= 0x80) {
                $start = $i;
                while ($i < $length && (ctype_alnum($sql[$i]) || '_' === $sql[$i] || '$' === $sql[$i] || \ord($sql[$i]) >= 0x80)) {
                    ++$i;
                }
                $tokens[] = new LintToken(LintToken::WORD, substr($sql, $start, $i - $start));
            } elseif ('"' === $char || ('`' === $char && SqlDialect::PostgreSql !== $dialect)) {
                $end = $i + 1;
                $name = '';
                while (true) {
                    $close = strpos($sql, $char, $end);
                    if (false === $close) {
                        return null;
                    }
                    $name .= substr($sql, $end, $close - $end);
                    if (($sql[$close + 1] ?? '') === $char) {
                        $name .= $char;
                        $end = $close + 2;

                        continue;
                    }
                    $i = $close + 1;

                    break;
                }
                $tokens[] = new LintToken(LintToken::IDENT, $name);
            } else {
                $tokens[] = new LintToken(LintToken::PUNCT, $char);
                ++$i;
            }
        }

        return $tokens;
    }

    /**
     * Les conditions (WHERE, ON) découpées en prédicats aux AND / OR de leur propre niveau de parenthèses.
     *
     * @param list<LintToken> $tokens
     *
     * @return list<array{int, int}> début et fin (exclue) de chaque prédicat
     */
    private function conditions(array $tokens): array
    {
        $slices = [];
        $open = [];
        $start = [];
        $between = [];
        $depth = 0;
        $count = \count($tokens);

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if ($token->isPunct('(')) {
                $before = $tokens[$i - 1] ?? null;
                $group = ($open[$depth] ?? false) && null !== $before && ($before->isWord('WHERE', 'ON', 'AND', 'OR', 'NOT') || $before->isPunct('('));
                ++$depth;
                $open[$depth] = $group;
                $start[$depth] = $i + 1;
                $between[$depth] = false;

                continue;
            }
            if ($token->isPunct(')')) {
                if ($open[$depth] ?? false) {
                    $slices[] = [$start[$depth], $i];
                }
                $open[$depth] = false;
                $depth = max(0, $depth - 1);

                continue;
            }
            if (LintToken::WORD !== $token->kind) {
                continue;
            }
            $isCall = ($tokens[$i + 1] ?? null)?->isPunct('(') ?? false;
            if ($token->isWord('WHERE', 'ON')) {
                if ($open[$depth] ?? false) {
                    $slices[] = [$start[$depth], $i];
                }
                $open[$depth] = true;
                $start[$depth] = $i + 1;
                $between[$depth] = false;

                continue;
            }
            if (!($open[$depth] ?? false)) {
                continue;
            }
            if (!$isCall && $token->isWord(...self::CLAUSE_ENDS)) {
                $slices[] = [$start[$depth], $i];
                $open[$depth] = false;
            } elseif ($token->isWord('BETWEEN')) {
                $between[$depth] = true;
            } elseif ($token->isWord('AND') && ($between[$depth] ?? false)) {
                $between[$depth] = false;
            } elseif ($token->isWord('AND', 'OR')) {
                $slices[] = [$start[$depth], $i];
                $start[$depth] = $i + 1;
            }
        }
        foreach ($open as $level => $isOpen) {
            if ($isOpen) {
                $slices[] = [$start[$level], $count];
            }
        }

        return $slices;
    }

    /**
     * @param list<LintToken> $slice
     */
    private function checkCondition(array $slice, SqlDialect $dialect): ?LintFinding
    {
        while (isset($slice[0]) && ($slice[0]->isPunct('(') || $slice[0]->isWord('NOT'))) {
            array_shift($slice);
        }
        if ([] === $slice) {
            return null;
        }

        return $this->checkFunction($slice, $dialect) ?? $this->checkArithmetic($slice, $dialect) ?? $this->checkLike($slice);
    }

    /**
     * @param list<LintToken> $slice
     */
    private function checkFunction(array $slice, SqlDialect $dialect): ?LintFinding
    {
        if (!$slice[0]->isWord(...self::FUNCTIONS) || !($slice[1] ?? null)?->isPunct('(')) {
            return null;
        }
        $depth = 0;
        $close = null;
        foreach ($slice as $index => $token) {
            if ($token->isPunct('(')) {
                ++$depth;
            } elseif ($token->isPunct(')') && 0 === --$depth) {
                $close = $index;

                break;
            }
        }
        $after = null === $close ? null : ($slice[$close + 1] ?? null);
        if (null === $close || !$this->isComparison($after)) {
            return null;
        }
        $arguments = \array_slice($slice, 2, $close - 2);
        foreach ($arguments as $argument) {
            if ($argument->isWord('SELECT')) {
                return null;
            }
        }
        $column = $this->firstColumn($arguments);
        if (null === $column) {
            return null;
        }
        $function = strtoupper($slice[0]->text);

        return new LintFinding(
            LintRule::FunctionOnColumn,
            Severity::Warning,
            \sprintf('%s() appliqué à la colonne « %s » dans une condition : un index sur cette colonne ne peut pas servir à la rechercher.', $function, $column),
            match ($dialect) {
                SqlDialect::PostgreSql => 'Comparer la colonne seule (par exemple une plage semi-ouverte pour une date), ou créer un index sur expression. Au-delà d\'une part importante des lignes, le parcours complet reste de toute façon choisi.',
                default => 'Comparer la colonne seule (par exemple une plage semi-ouverte pour une date), ou créer un index fonctionnel (MySQL 8) ou une colonne générée indexée. Au-delà d\'une part importante des lignes, le parcours complet reste de toute façon choisi.',
            },
            [$column],
        );
    }

    /**
     * @param list<LintToken> $slice
     */
    private function checkArithmetic(array $slice, SqlDialect $dialect): ?LintFinding
    {
        [$column, $next] = $this->columnAt($slice, 0);
        if (null === $column) {
            return null;
        }
        $operator = $slice[$next] ?? null;
        if (null === $operator || LintToken::PUNCT !== $operator->kind || !\in_array($operator->text, ['+', '-', '*', '/', '%'], true)) {
            return null;
        }
        $operand = $slice[$next + 1] ?? null;
        if (null === $operand) {
            return null;
        }
        if (LintToken::NUMBER === $operand->kind) {
            $after = $slice[$next + 2] ?? null;
        } else {
            [$other, $afterOperand] = $this->columnAt($slice, $next + 1);
            if (null === $other) {
                return null;
            }
            $after = $slice[$afterOperand] ?? null;
        }
        if (!$this->isComparison($after) || (null !== $after && !\in_array($after->text, ['=', '<', '>', '!'], true))) {
            return null;
        }

        return new LintFinding(
            LintRule::ArithmeticOnColumn,
            Severity::Warning,
            \sprintf('Un calcul sur la colonne « %s » dans une condition : un index sur cette colonne ne peut pas servir à la rechercher.', $column),
            'Isoler la colonne d\'un côté de la comparaison (déplacer le calcul vers la valeur comparée). Au-delà d\'une part importante des lignes, le parcours complet reste de toute façon choisi.',
            [$column],
        );
    }

    /**
     * @param list<LintToken> $slice
     */
    private function checkLike(array $slice): ?LintFinding
    {
        [$column, $next] = $this->columnAt($slice, 0);
        if (null === $column) {
            return null;
        }
        if (($slice[$next] ?? null)?->isWord('NOT')) {
            ++$next;
        }
        $pattern = $slice[$next + 1] ?? null;
        if (!($slice[$next] ?? null)?->isWord('LIKE', 'ILIKE') || null === $pattern || LintToken::STRING !== $pattern->kind) {
            return null;
        }
        if (!str_starts_with($pattern->text, '%') || '%' === $pattern->text) {
            return null;
        }

        return new LintFinding(
            LintRule::LeadingWildcard,
            Severity::Warning,
            \sprintf('LIKE avec un joker en tête sur « %s » : un index ordinaire ne peut pas servir, la recherche lit toute la colonne.', $column),
            'Si la recherche porte sur un mot ou une sous-chaîne : plein texte (FULLTEXT en MySQL/MariaDB, index trigramme pg_trgm en PostgreSQL). Si la fin du texte compte : un index sur la valeur inversée. Un préfixe (\'abc%\') garde l\'index.',
            [$column],
        );
    }

    /**
     * @param list<LintToken> $tokens
     *
     * @return list<LintFinding>
     */
    private function notIn(array $tokens): array
    {
        $found = [];
        foreach ($tokens as $i => $token) {
            if ($token->isWord('NOT') && ($tokens[$i + 1] ?? null)?->isWord('IN') && ($tokens[$i + 2] ?? null)?->isPunct('(') && ($tokens[$i + 3] ?? null)?->isWord('SELECT')) {
                $found[] = new LintFinding(
                    LintRule::NotInSubquery,
                    Severity::Info,
                    'NOT IN (sous-requête) : si la sous-requête peut renvoyer un NULL, la condition n\'est jamais vraie et aucune ligne ne ressort ; l\'optimiseur le planifie aussi souvent moins bien.',
                    'NOT EXISTS (SELECT 1 FROM … WHERE …) donne le résultat attendu même avec des NULL.',
                );
            }
        }

        return $found;
    }

    /**
     * @param list<LintToken> $tokens
     *
     * @return list<LintFinding>
     */
    private function locks(array $tokens): array
    {
        foreach ($tokens as $i => $token) {
            $lock = ($token->isWord('FOR') && ($tokens[$i + 1] ?? null)?->isWord('UPDATE', 'SHARE', 'NO', 'KEY'))
                || ($token->isWord('LOCK') && ($tokens[$i + 1] ?? null)?->isWord('IN') && ($tokens[$i + 2] ?? null)?->isWord('SHARE'));
            if ($lock) {
                return [new LintFinding(
                    LintRule::RowLock,
                    Severity::Info,
                    'Un verrou de lignes (FOR UPDATE, FOR SHARE) n\'a de sens que dans une transaction ; TerrilDB exécute chaque instruction seule (autocommit), où le verrou est relâché aussitôt et SKIP LOCKED ne protège rien.',
                    'Poser le verrou depuis l\'application, dans la transaction qui lit puis écrit.',
                )];
            }
        }

        return [];
    }

    /**
     * @param list<LintToken> $tokens
     *
     * @return list<LintFinding>
     */
    private function recursive(array $tokens): array
    {
        foreach ($tokens as $i => $token) {
            if ($token->isWord('WITH') && ($tokens[$i + 1] ?? null)?->isWord('RECURSIVE')) {
                return [new LintFinding(
                    LintRule::RecursiveCte,
                    Severity::Info,
                    'WITH RECURSIVE : une récursion qui ne se termine pas tourne jusqu\'au délai et à la limite de lignes de TerrilDB.',
                    'Ajouter un compteur de profondeur (depth + 1) et une condition d\'arrêt (WHERE depth < N).',
                )];
            }
        }

        return [];
    }

    /**
     * @param list<LintToken> $tokens
     *
     * @return list<LintFinding>
     */
    private function selectStar(array $tokens): array
    {
        $depth = 0;
        $inList = false;
        foreach ($tokens as $i => $token) {
            if ($token->isPunct('(')) {
                ++$depth;
            } elseif ($token->isPunct(')')) {
                $depth = max(0, $depth - 1);
            } elseif (0 === $depth && $token->isWord('SELECT')) {
                $inList = true;
            } elseif (0 === $depth && $token->isWord('FROM')) {
                $inList = false;
            } elseif (0 === $depth && $inList && $token->isPunct('*')) {
                $before = $tokens[$i - 1] ?? null;
                $after = $tokens[$i + 1] ?? null;
                $starts = null !== $before && ($before->isWord('SELECT', 'DISTINCT') || $before->isPunct(',') || $before->isPunct('.'));
                $ends = null !== $after && ($after->isPunct(',') || $after->isWord('FROM'));
                if ($starts && $ends) {
                    return [new LintFinding(
                        LintRule::SelectStar,
                        Severity::Info,
                        'SELECT * lit toutes les colonnes : plus de données transférées, et aucun index couvrant ne peut répondre seul à la requête.',
                        'Lister les colonnes dont on a besoin.',
                    )];
                }
            }
        }

        return [];
    }

    /**
     * @param list<LintToken> $tokens
     *
     * @return list<LintFinding>
     */
    private function largeOffset(array $tokens, bool $commaForm): array
    {
        $depth = 0;
        foreach ($tokens as $i => $token) {
            if ($token->isPunct('(')) {
                ++$depth;
            } elseif ($token->isPunct(')')) {
                $depth = max(0, $depth - 1);
            } elseif (0 === $depth) {
                $offset = null;
                if ($token->isWord('OFFSET')) {
                    $offset = $tokens[$i + 1] ?? null;
                } elseif ($commaForm && $token->isWord('LIMIT') && ($tokens[$i + 2] ?? null)?->isPunct(',')) {
                    $offset = $tokens[$i + 1] ?? null;
                }
                if (null !== $offset && LintToken::NUMBER === $offset->kind && ctype_digit($offset->text) && (int) $offset->text >= self::LARGE_OFFSET) {
                    return [new LintFinding(
                        LintRule::LargeOffset,
                        Severity::Info,
                        \sprintf('OFFSET %s : le moteur lit puis écarte toutes les lignes précédentes, de plus en plus lentement à chaque page.', $offset->text),
                        'Paginer par clé : WHERE clé > dernière valeur vue ORDER BY clé LIMIT n.',
                    )];
                }
            }
        }

        return [];
    }

    private function isComparison(?LintToken $token): bool
    {
        if (null === $token) {
            return false;
        }
        if (LintToken::PUNCT === $token->kind) {
            return \in_array($token->text, ['=', '<', '>', '!'], true);
        }

        return $token->isWord('LIKE', 'ILIKE', 'IN', 'BETWEEN', 'IS', 'NOT');
    }

    /**
     * Le nom de la première colonne lue dans une suite de mots (mot qui n'est ni un appel, ni un mot réservé, ni une variable).
     *
     * @param list<LintToken> $tokens
     */
    private function firstColumn(array $tokens): ?string
    {
        for ($i = 0, $count = \count($tokens); $i < $count; ++$i) {
            $before = $tokens[$i - 1] ?? null;
            if (null !== $before && (\in_array($before->text, ['@', ':', '$'], true) && LintToken::PUNCT === $before->kind)) {
                continue;
            }
            [$column] = $this->columnAt($tokens, $i);
            if (null !== $column) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Une colonne (éventuellement qualifiée) qui commence à cette position : son nom et la position qui suit.
     *
     * @param list<LintToken> $tokens
     *
     * @return array{?string, int}
     */
    private function columnAt(array $tokens, int $at): array
    {
        $i = $at;
        $name = null;
        while (isset($tokens[$i])) {
            $token = $tokens[$i];
            $isName = LintToken::IDENT === $token->kind || (LintToken::WORD === $token->kind && !\in_array($token->upper, self::NOT_COLUMNS, true));
            if (!$isName || ($tokens[$i + 1] ?? null)?->isPunct('(')) {
                return [null, $at];
            }
            $name = $token->text;
            ++$i;
            if (($tokens[$i] ?? null)?->isPunct('.')) {
                ++$i;

                continue;
            }

            return [$name, $i];
        }

        return [null, $at];
    }
}

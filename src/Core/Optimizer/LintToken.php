<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

/**
 * Un élément lexical lu par le linter. `text` est le texte brut (sans guillemets pour un identifiant ou une chaîne) ;
 * `upper` n'a de sens que pour un mot.
 */
final readonly class LintToken
{
    public const WORD = 'word';
    public const IDENT = 'ident';
    public const STRING = 'string';
    public const NUMBER = 'number';
    public const PUNCT = 'punct';

    public string $upper;

    public function __construct(public string $kind, public string $text)
    {
        $this->upper = strtoupper($text);
    }

    public function isPunct(string $char): bool
    {
        return self::PUNCT === $this->kind && $char === $this->text;
    }

    public function isWord(string ...$words): bool
    {
        return self::WORD === $this->kind && \in_array($this->upper, $words, true);
    }
}

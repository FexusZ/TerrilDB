<?php

declare(strict_types=1);

namespace App\Core\Guard;

/**
 * Une instruction réduite à ses mots-clés et identifiants, en majuscules, dans l'ordre : commentaires,
 * chaînes et identifiants entre guillemets ont disparu.
 */
final readonly class ScannedStatement
{
    /**
     * @param non-empty-list<string> $words
     * @param list<int>              $depths profondeur de parenthèses de chaque mot (0 = niveau de l'instruction)
     */
    public function __construct(public array $words, private array $depths)
    {
        if (\count($depths) !== \count($words)) {
            throw new \InvalidArgumentException('Each word needs a depth.');
        }
    }

    public function first(): string
    {
        return $this->words[0];
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

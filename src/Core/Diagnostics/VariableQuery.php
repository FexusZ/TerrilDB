<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

use App\Core\Diagnostics\Exception\InvalidVariableQueryException;

/**
 * Ce qu'on demande à la liste des variables : un morceau de nom (sans casse, lu littéralement : « _ » et « % » ne sont pas des
 * jokers), seulement celles qui diffèrent du défaut, une limite bornée.
 */
final readonly class VariableQuery
{
    public const DEFAULT_LIMIT = 100;
    public const MAX_LIMIT = 500;
    public const MAX_SEARCH_LENGTH = 64;

    public ?string $search;

    public function __construct(
        ?string $search = null,
        public bool $changedOnly = false,
        public int $limit = self::DEFAULT_LIMIT,
    ) {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidVariableQueryException(\sprintf('The limit must be between 1 and %d.', self::MAX_LIMIT));
        }
        $search = null === $search ? null : trim($search);
        if (null !== $search && mb_strlen($search) > self::MAX_SEARCH_LENGTH) {
            throw new InvalidVariableQueryException(\sprintf('The search holds at most %d characters.', self::MAX_SEARCH_LENGTH));
        }
        $this->search = '' === $search ? null : $search;
    }
}

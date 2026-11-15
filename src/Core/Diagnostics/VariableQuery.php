<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

use App\Core\Diagnostics\Exception\InvalidVariableQueryException;

/**
 * Ce qu'on demande à la liste des variables : un morceau de nom (sans casse, lu littéralement : « _ » et « % » ne sont pas des
 * jokers), seulement celles qui diffèrent du défaut, une limite bornée, ou une liste de noms exacts (pour lire précisément ce qu'une règle regarde).
 */
final readonly class VariableQuery
{
    public const DEFAULT_LIMIT = 100;
    public const MAX_LIMIT = 500;
    public const MAX_SEARCH_LENGTH = 64;
    public const MAX_NAMES = 500;
    public const MAX_NAME_LENGTH = 128;

    public ?string $search;
    /** Noms exacts demandés (sans casse, en minuscules ici) ; vide = pas de filtre par nom. @var list<string> */
    public array $names;

    public function __construct(
        ?string $search = null,
        public bool $changedOnly = false,
        public int $limit = self::DEFAULT_LIMIT,
        array $names = [],
    ) {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidVariableQueryException(\sprintf('The limit must be between 1 and %d.', self::MAX_LIMIT));
        }
        $search = null === $search ? null : trim($search);
        if (null !== $search && mb_strlen($search) > self::MAX_SEARCH_LENGTH) {
            throw new InvalidVariableQueryException(\sprintf('The search holds at most %d characters.', self::MAX_SEARCH_LENGTH));
        }
        $this->search = '' === $search ? null : $search;
        $clean = [];
        foreach ($names as $name) {
            $name = strtolower(trim($name));
            if ('' === $name) {
                continue;
            }
            if (1 !== preg_match('/^[a-z0-9_.$-]+$/D', $name)) {
                throw new InvalidVariableQueryException('A variable name holds letters, digits and _ . $ - only.');
            }
            if (\strlen($name) > self::MAX_NAME_LENGTH) {
                throw new InvalidVariableQueryException(\sprintf('A variable name holds at most %d characters.', self::MAX_NAME_LENGTH));
            }
            $clean[$name] = true;
        }
        if (\count($clean) > self::MAX_NAMES) {
            throw new InvalidVariableQueryException(\sprintf('At most %d names can be asked at once.', self::MAX_NAMES));
        }
        $this->names = array_map(strval(...), array_keys($clean));
    }
}

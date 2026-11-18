<?php

declare(strict_types=1);

namespace App\Core\Schema;

/**
 * Les objets dont le créateur n'existe plus parmi les comptes du serveur, ou la raison stable pour laquelle la liste des
 * comptes n'a pas pu être lue (`permission_denied`, `unreadable`, `not_supported`).
 */
final readonly class DefinerReport
{
    /**
     * @param list<DefinerObject> $objects
     */
    private function __construct(
        public bool $available,
        public ?string $reason,
        public array $objects,
    ) {
    }

    /**
     * @param list<DefinerObject> $objects
     */
    public static function of(array $objects): self
    {
        return new self(true, null, $objects);
    }

    public static function unavailable(string $reason): self
    {
        return new self(false, $reason, []);
    }
}

<?php

declare(strict_types=1);

namespace App\Core\KeyValue;

/**
 * Une page de clés d'un SCAN. `nextCursor` est null quand le parcours est fini ; une page peut être vide alors qu'il
 * en reste (SCAN peut ne rien trouver dans une tranche).
 */
final readonly class KeyPage
{
    /**
     * @param list<KeyEntry> $entries
     */
    public function __construct(public array $entries, public ?string $nextCursor)
    {
    }

    public function hasMore(): bool
    {
        return null !== $this->nextCursor;
    }
}

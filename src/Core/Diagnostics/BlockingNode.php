<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * Un nœud de l'arbre de blocage : une session et, dessous, celles qui attendent après elle. Les informations d'attente
 * (durée, mode, objet) sont celles de CETTE session vis-à-vis de son parent ; elles sont nulles pour une racine, qui n'attend personne.
 */
final readonly class BlockingNode
{
    /**
     * @param list<BlockingNode> $blocked
     */
    public function __construct(
        public LockParty $party,
        public ?int $seconds,
        public ?LockKind $kind,
        public ?string $mode,
        public ?string $object,
        public array $blocked,
    ) {
    }

    public function count(): int
    {
        return array_reduce($this->blocked, static fn (int $n, self $child): int => $n + $child->count(), 0) + 1;
    }
}

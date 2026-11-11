<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * Transactions ouvertes et attentes de verrous d'un serveur, avec l'état de la source : « aucun verrou » et « non lisible »
 * ne se confondent pas.
 */
final readonly class LockReport
{
    /**
     * @param list<OpenTransaction> $transactions les plus anciennes d'abord
     * @param list<LockWait>        $waits
     */
    public function __construct(
        public DiagnosticStatus $status,
        public array $transactions,
        public array $waits,
        /** Vrai si le serveur en a davantage que la borne posée. */
        public bool $truncated,
    ) {
    }

    /**
     * @return list<BlockingNode>
     */
    public function tree(): array
    {
        return BlockingTree::build($this->waits);
    }
}

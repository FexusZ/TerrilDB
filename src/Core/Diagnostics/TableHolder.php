<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * Une session qui tient une table : un ALTER attendrait qu'elle ait fini. Le texte de sa requête n'est jamais repris ici.
 */
final readonly class TableHolder
{
    public function __construct(
        public string $session,
        public ?string $user,
        /** Âge de sa transaction, quand le moteur le dit. */
        public ?int $seconds,
        /** Elle n'exécute rien : la transaction est restée ouverte. */
        public bool $sleeping,
        public ?string $mode,
    ) {
        if ('' === $session) {
            throw new \InvalidArgumentException('A holder is a session.');
        }
    }
}

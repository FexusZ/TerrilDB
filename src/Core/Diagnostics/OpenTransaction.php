<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

use App\Core\Guard\SqlDialect;

/**
 * Une transaction ouverte sur le serveur cible. Une transaction « en sommeil » (la session n'exécute rien) est celle qu'on a
 * oubliée : elle garde ses verrous et retarde le nettoyage des anciennes versions de lignes.
 */
final readonly class OpenTransaction
{
    public ?string $query;
    public bool $queryRedacted;

    public function __construct(
        /** Identifiant de la session qui la tient. */
        public string $session,
        public ?string $user,
        public ?string $state,
        /** Âge de la transaction. */
        public int $seconds,
        /** Nombre de lignes verrouillées, quand le moteur le dit. */
        public ?int $rowsLocked,
        public ?int $rowsModified,
        public bool $sleeping,
        ?string $query,
        SqlDialect $dialect,
    ) {
        if ('' === $session) {
            throw new \InvalidArgumentException('A transaction belongs to a session.');
        }
        $text = QueryText::of($query, $dialect);
        $this->query = $text->text;
        $this->queryRedacted = $text->redacted;
    }
}

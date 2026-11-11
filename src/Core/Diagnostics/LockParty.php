<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * Une session prise dans une attente de verrou. L'identifiant est celui de la session (celui qu'une instruction d'arrêt viserait,
 * que TerrilDB n'exécute jamais) ; le texte est raccourci comme celui d'une session.
 */
final readonly class LockParty
{
    public ?string $query;

    public function __construct(
        public string $id,
        public ?string $user,
        ?string $query,
    ) {
        if ('' === $id) {
            throw new \InvalidArgumentException('A session needs an identifier.');
        }
        $this->query = QueryText::shorten($query);
    }
}

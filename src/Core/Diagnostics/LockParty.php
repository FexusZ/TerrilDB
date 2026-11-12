<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

use App\Core\Guard\SqlDialect;

/**
 * Une session prise dans une attente de verrou. L'identifiant est celui de la session (celui qu'une instruction d'arrêt viserait,
 * que TerrilDB n'exécute jamais) ; le texte est masqué puis raccourci comme celui d'une session (`QueryText`).
 */
final readonly class LockParty
{
    public ?string $query;
    public bool $queryRedacted;

    public function __construct(
        public string $id,
        public ?string $user,
        ?string $query,
        SqlDialect $dialect,
    ) {
        if ('' === $id) {
            throw new \InvalidArgumentException('A session needs an identifier.');
        }
        $text = QueryText::of($query, $dialect);
        $this->query = $text->text;
        $this->queryRedacted = $text->redacted;
    }
}

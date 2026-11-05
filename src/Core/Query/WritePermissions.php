<?php

declare(strict_types=1);

namespace App\Core\Query;

/**
 * Ce qu'une exécution en lecture-écriture a le droit de modifier : les lignes (INSERT, UPDATE, DELETE), la structure
 * (CREATE, ALTER, DROP, TRUNCATE, GRANT, CALL…) ou les deux. Sans effet en lecture seule, où rien ne s'écrit.
 * Le garde les applique de façon lexicale ; seule la lecture seule est aussi tenue par le moteur.
 */
final readonly class WritePermissions
{
    public function __construct(
        public bool $data = false,
        public bool $schema = false,
    ) {
    }

    public static function all(): self
    {
        return new self(true, true);
    }
}

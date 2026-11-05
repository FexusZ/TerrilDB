<?php

declare(strict_types=1);

namespace App\ApiToken;

use App\Core\Query\WritePermissions;

/**
 * Ce qu'un jeton a le droit de faire en plus de lire les connexions de sa liste. Tout est refusé par défaut : un jeton
 * ne fait que lire, et chaque droit se coche à la création (il ne change pas ensuite, on émet un autre jeton).
 */
final readonly class TokenPermissions
{
    public function __construct(
        /** INSERT, UPDATE, DELETE et équivalents. */
        public bool $writeData = false,
        /** CREATE, ALTER, DROP, TRUNCATE, GRANT et équivalents. */
        public bool $writeSchema = false,
        /** Enregistrer de nouvelles connexions dans le compte. */
        public bool $createConnections = false,
    ) {
    }

    public function writes(): WritePermissions
    {
        return new WritePermissions($this->writeData, $this->writeSchema);
    }

    public function canWrite(): bool
    {
        return $this->writeData || $this->writeSchema;
    }
}

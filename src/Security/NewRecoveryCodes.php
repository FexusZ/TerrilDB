<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Codes de récupération tout juste générés : $codes ne s'affichent qu'une fois à l'utilisateur,
 * seuls $hashes (même ordre) sont conservés.
 */
final readonly class NewRecoveryCodes
{
    /**
     * @param list<string> $codes
     * @param list<string> $hashes
     */
    public function __construct(
        #[\SensitiveParameter] public array $codes,
        public array $hashes,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\ApiToken;

use App\Entity\ApiToken;

/**
 * Un jeton qui vient d'être émis. Le texte du jeton n'existe que dans cet objet : il est montré une fois à
 * l'utilisateur puis jeté, le serveur n'en garde que l'empreinte.
 */
final readonly class IssuedApiToken
{
    public function __construct(
        #[\SensitiveParameter] public string $token,
        public ApiToken $entity,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['token' => '***', 'entity' => $this->entity->getId()->toRfc4122()];
    }
}

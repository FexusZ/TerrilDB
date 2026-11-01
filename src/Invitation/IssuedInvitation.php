<?php

declare(strict_types=1);

namespace App\Invitation;

use App\Entity\Invitation;

/**
 * Une invitation qui vient d'être émise. Son texte n'existe que dans cet objet : il est montré une fois à
 * l'administrateur puis jeté, le serveur n'en garde que l'empreinte.
 */
final readonly class IssuedInvitation
{
    public function __construct(
        #[\SensitiveParameter] public string $token,
        public Invitation $entity,
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

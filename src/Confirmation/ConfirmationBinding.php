<?php

declare(strict_types=1);

namespace App\Confirmation;

use Symfony\Component\Uid\Uuid;

/**
 * Ce à quoi un jeton de confirmation est lié : le jeton d'API qui l'a demandé, la connexion visée, la nature (une
 * instruction ou un script), le texte EXACT (octet pour octet) et le verdict du garde au moment de la préparation
 * (`allowed` ou `needs_confirmation`). Changer l'un d'eux rend le jeton inutilisable.
 */
final readonly class ConfirmationBinding
{
    public function __construct(
        public Uuid $apiTokenId,
        public Uuid $connectionId,
        public ConfirmationKind $kind,
        public string $text,
        public string $decision,
    ) {
    }

    public function textHash(): string
    {
        return hash('sha256', $this->text);
    }
}

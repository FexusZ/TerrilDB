<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Repository\UserRepository;

/**
 * Qui peut créer un compte. En attendant les invitations : `first_only` (défaut) n'accepte que le premier compte
 * d'une installation vide, `open` accepte tout le monde. Deux premières inscriptions simultanées peuvent passer
 * toutes les deux : acceptable pour une installation que son propriétaire configure lui-même.
 */
final readonly class RegistrationPolicy
{
    public function __construct(
        private string $mode,
        private UserRepository $users,
    ) {
    }

    public function isOpen(): bool
    {
        return 'open' === $this->mode || 0 === $this->users->count([]);
    }
}

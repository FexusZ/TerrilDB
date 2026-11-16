<?php

declare(strict_types=1);

namespace App\Confirmation;

use App\Core\Guard\GuardReason;
use App\Core\Guard\StatementKind;

/**
 * Ce que rend une préparation : le verdict du garde (rien n'est parti), la nature de l'instruction, son niveau de danger
 * et le jeton de confirmation (à usage unique) à renvoyer avec le texte exact.
 */
final readonly class PreparedWrite
{
    public function __construct(
        public string $decision,
        public StatementKind $kind,
        public ?GuardReason $reason,
        public IssuedConfirmation $confirmation,
    ) {
    }

    public function danger(): string
    {
        return 'needs_confirmation' === $this->decision ? 'destructive' : 'normal';
    }
}

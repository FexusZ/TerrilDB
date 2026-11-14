<?php

declare(strict_types=1);

namespace App\Http\Json;

use App\Confirmation\ConfirmationTickets;
use App\Confirmation\PreparedScript;
use App\Confirmation\PreparedWrite;

final class PreparedWritePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(PreparedWrite $prepared): array
    {
        return [
            'decision' => $prepared->decision,
            'kind' => strtolower($prepared->kind->name),
            'danger' => $prepared->danger(),
            'reason' => $prepared->reason?->value,
            'confirmationToken' => $prepared->confirmation->token,
            'expiresAt' => $prepared->confirmation->expiresAt->format(\DATE_ATOM),
            'expiresInSeconds' => ConfirmationTickets::LIFETIME_SECONDS,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function script(PreparedScript $prepared): array
    {
        return [
            'decision' => $prepared->decision,
            'danger' => 'needs_confirmation' === $prepared->decision ? 'destructive' : 'normal',
            'confirmationToken' => $prepared->confirmation->token,
            'expiresAt' => $prepared->confirmation->expiresAt->format(\DATE_ATOM),
            'expiresInSeconds' => ConfirmationTickets::LIFETIME_SECONDS,
        ] + ScriptPresenter::preview($prepared->preview);
    }
}

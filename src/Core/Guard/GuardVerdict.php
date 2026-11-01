<?php

declare(strict_types=1);

namespace App\Core\Guard;

final readonly class GuardVerdict
{
    private function __construct(
        public Decision $decision,
        public ?StatementKind $kind,
        public ?GuardReason $reason,
    ) {
    }

    public static function allowed(StatementKind $kind): self
    {
        return new self(Decision::Allowed, $kind, null);
    }

    public static function needsConfirmation(StatementKind $kind, GuardReason $reason): self
    {
        return new self(Decision::NeedsConfirmation, $kind, $reason);
    }

    public static function refused(GuardReason $reason, ?StatementKind $kind = null): self
    {
        return new self(Decision::Refused, $kind, $reason);
    }

    /**
     * Vrai si l'instruction peut partir telle quelle, sans confirmation.
     */
    public function allowsExecution(): bool
    {
        return Decision::Allowed === $this->decision;
    }
}

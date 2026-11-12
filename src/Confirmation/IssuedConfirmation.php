<?php

declare(strict_types=1);

namespace App\Confirmation;

final readonly class IssuedConfirmation
{
    public function __construct(public string $token, public \DateTimeImmutable $expiresAt)
    {
    }
}

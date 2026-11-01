<?php

declare(strict_types=1);

namespace App\Security\Exception;

final class TotpNotEnabledException extends \DomainException
{
    public static function create(): self
    {
        return new self('TOTP is not enabled for this account.');
    }
}

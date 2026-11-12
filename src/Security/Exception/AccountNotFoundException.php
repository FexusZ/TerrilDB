<?php

declare(strict_types=1);

namespace App\Security\Exception;

final class AccountNotFoundException extends \DomainException
{
    public static function create(): self
    {
        return new self('Account not found.');
    }
}

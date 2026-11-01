<?php

declare(strict_types=1);

namespace App\Invitation\Exception;

final class NotAnAdministratorException extends \DomainException
{
    public static function create(): self
    {
        return new self('Only an administrator manages invitations.');
    }
}

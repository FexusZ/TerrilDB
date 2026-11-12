<?php

declare(strict_types=1);

namespace App\Security\Exception;

/**
 * Désactivation refusée : on ne désactive pas son propre compte, ni le dernier administrateur actif
 * (l'installation n'aurait plus personne pour la gérer).
 */
final class CannotDisableAccountException extends \DomainException
{
    public const SELF = 'cannot_disable_self';
    public const LAST_ADMINISTRATOR = 'last_administrator';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function self(): self
    {
        return new self(self::SELF, 'An administrator cannot disable their own account.');
    }

    public static function lastAdministrator(): self
    {
        return new self(self::LAST_ADMINISTRATOR, 'The last active administrator cannot be disabled.');
    }
}

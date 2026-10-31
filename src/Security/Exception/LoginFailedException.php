<?php

declare(strict_types=1);

namespace App\Security\Exception;

/**
 * Racine des refus de connexion : un appelant peut tous les intercepter d'un coup.
 */
abstract class LoginFailedException extends \RuntimeException
{
}

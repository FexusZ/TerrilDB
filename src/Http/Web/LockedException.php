<?php

declare(strict_types=1);

namespace App\Http\Web;

/**
 * La session est ouverte mais sa clé de chiffrement n'est plus disponible (cookie de déverrouillage perdu,
 * session expirée côté coffre) : il faut se reconnecter pour la retrouver.
 */
final class LockedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The session is locked.');
    }
}

<?php

declare(strict_types=1);

namespace App\Crypto\Exception;

/**
 * Racine de toutes les erreurs du namespace Crypto : un appelant peut tout intercepter d'un coup.
 */
abstract class CryptoException extends \RuntimeException
{
}

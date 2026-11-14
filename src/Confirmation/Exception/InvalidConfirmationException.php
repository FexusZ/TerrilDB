<?php

declare(strict_types=1);

namespace App\Confirmation\Exception;

/**
 * Jeton de confirmation inconnu, expiré, déjà utilisé, ou lié à autre chose que ce qui est présenté. Un seul message :
 * on ne dit jamais lequel, et le jeton présenté est consommé dans tous les cas.
 */
final class InvalidConfirmationException extends \DomainException
{
    public static function create(): self
    {
        return new self('The confirmation token is not valid.');
    }
}

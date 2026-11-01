<?php

declare(strict_types=1);

namespace App\Invitation\Exception;

/**
 * Le transport a échoué. Le message ne cite ni l'adresse ni le jeton ; le détail (serveur SMTP, etc.) reste dans
 * l'exception précédente, pour le journal du serveur seulement.
 */
final class MailNotSentException extends \RuntimeException
{
    public static function create(\Throwable $previous): self
    {
        return new self('The invitation e-mail could not be sent.', previous: $previous);
    }
}

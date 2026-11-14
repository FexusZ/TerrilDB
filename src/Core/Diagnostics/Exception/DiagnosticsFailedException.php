<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Exception;

/**
 * Une lecture de supervision a échoué pour une raison que l'état de disponibilité ne couvre pas. Le message est générique :
 * le détail du moteur reste dans l'exception précédente et n'est jamais montré.
 */
final class DiagnosticsFailedException extends \RuntimeException
{
    public static function create(\Throwable $previous): self
    {
        return new self('The server diagnostics could not be read.', previous: $previous);
    }
}

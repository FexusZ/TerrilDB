<?php

declare(strict_types=1);

namespace App\Core\Driver\Exception;

use App\Core\Driver\LockConflict;

/**
 * Un verrou a fait échouer l'instruction (interblocage ou délai de verrou dépassé). Le message du moteur reste dans l'exception
 * précédente : il peut citer une valeur de l'utilisateur et n'est jamais montré.
 */
final class LockConflictException extends \RuntimeException
{
    public function __construct(public readonly LockConflict $conflict, ?\Throwable $previous = null)
    {
        parent::__construct(\sprintf('The engine reported a lock conflict (%s).', $conflict->code()), 0, $previous);
    }
}

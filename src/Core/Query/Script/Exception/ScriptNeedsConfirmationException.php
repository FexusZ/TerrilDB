<?php

declare(strict_types=1);

namespace App\Core\Query\Script\Exception;

/**
 * Le script contient des instructions destructrices : il ne part que renvoyé avec UNE confirmation pour l'ensemble.
 */
final class ScriptNeedsConfirmationException extends \RuntimeException
{
    public function __construct(public readonly int $destructiveCount)
    {
        parent::__construct('The script needs a confirmation.');
    }
}

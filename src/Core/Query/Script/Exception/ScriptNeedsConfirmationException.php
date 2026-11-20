<?php

declare(strict_types=1);

namespace App\Core\Query\Script\Exception;

/**
 * Le script contient des instructions destructrices : il ne part que renvoyé avec UNE confirmation pour l'ensemble.
 */
final class ScriptNeedsConfirmationException extends \RuntimeException
{
    /**
     * @param string $reason `destructive_script` (défaut) ou `stream_source` : Vitess, une instruction touche une table qui nourrit un flux
     */
    public function __construct(public readonly int $destructiveCount, public readonly string $reason = 'destructive_script')
    {
        parent::__construct('The script needs a confirmation.');
    }
}

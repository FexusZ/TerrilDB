<?php

declare(strict_types=1);

namespace App\Core\Optimizer\Exception;

/**
 * Le compte a déjà son quota de mesures de plan en cours : chacune rejoue la requête et garde un processus du serveur jusqu'au délai.
 */
final class MeasureBusyException extends \RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Too many plan measures are already running.');
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Health;

/**
 * Le compte a déjà son quota de mesures de santé en cours : chacune garde un processus du serveur pendant tout son intervalle.
 */
final class HealthBusyException extends \RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Too many health measurements are already running.');
    }
}

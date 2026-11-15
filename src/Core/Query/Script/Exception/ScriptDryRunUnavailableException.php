<?php

declare(strict_types=1);

namespace App\Core\Query\Script\Exception;

/**
 * Le moteur ne sait pas annuler ce script : `reason` vaut `no_transaction` ou `implicit_commit`.
 */
final class ScriptDryRunUnavailableException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('A dry run is not available for this script.');
    }
}

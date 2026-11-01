<?php

declare(strict_types=1);

namespace App\Core\Optimizer\Exception;

/**
 * Le moteur a rendu un plan que l'on ne sait pas lire (autre version, autre forme).
 */
final class UnreadablePlanException extends \RuntimeException
{
    public static function create(): self
    {
        return new self('The execution plan could not be read.');
    }
}

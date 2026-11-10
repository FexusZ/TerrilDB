<?php

declare(strict_types=1);

namespace App\Core\Guard;

/**
 * Une instruction du script, avec le verdict du garde pour elle seule.
 */
final readonly class ScriptStep
{
    public function __construct(public int $index, public string $sql, public GuardVerdict $verdict)
    {
    }
}

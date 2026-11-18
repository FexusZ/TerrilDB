<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

/**
 * Une règle d'exploitation qui n'a pas pu juger faute de source lisible : jamais un constat. `reason` est le code stable du rapport.
 */
final readonly class OperationsCheck
{
    public function __construct(
        public FindingRule $rule,
        public string $reason,
    ) {
    }
}

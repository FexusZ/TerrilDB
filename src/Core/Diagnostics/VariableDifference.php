<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * Une variable qui n'a pas la même valeur sur les deux serveurs (ou n'existe que sur un). Jamais une variable masquée : le
 * comparateur les écarte avant d'en faire une différence.
 */
final readonly class VariableDifference
{
    public function __construct(
        public string $name,
        public bool $presentA,
        public ?string $valueA,
        public bool $changedA,
        public bool $presentB,
        public ?string $valueB,
        public bool $changedB,
        public ?string $unit,
        /** Les deux textes montrés sont identiques (coupés à `ServerVariable::MAX_VALUE_LENGTH`) : la différence est plus loin. */
        public bool $beyondDisplay = false,
    ) {
    }
}

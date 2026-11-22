<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Vitess;

/**
 * Une variable qui n'a pas la même valeur sur tous les tablets lus. `values` : alias => valeur, ou nul quand la variable est à son défaut
 * (elle n'est pas dans les variables modifiées de ce tablet).
 */
final readonly class TabletVariableDifference
{
    /**
     * @param array<string, ?string> $values
     */
    public function __construct(public string $name, public array $values, public ?string $unit)
    {
    }
}

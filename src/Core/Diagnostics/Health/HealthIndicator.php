<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Health;

final readonly class HealthIndicator
{
    /**
     * @param string     $unit      `per_second`, `percent` ou `count`
     * @param float|null $threshold seuil d'avertissement (même unité), null s'il n'y en a pas
     */
    public function __construct(
        public string $key,
        public ?float $value,
        public string $unit,
        public HealthLevel $level,
        public ?float $threshold,
    ) {
    }
}

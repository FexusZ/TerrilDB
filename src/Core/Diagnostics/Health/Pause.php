<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Health;

/**
 * L'attente entre deux relevés, derrière une interface pour que les tests n'attendent pas.
 */
interface Pause
{
    public function sleep(float $seconds): void;
}

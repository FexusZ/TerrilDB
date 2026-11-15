<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Health;

final readonly class UsleepPause implements Pause
{
    public function sleep(float $seconds): void
    {
        usleep((int) round($seconds * 1_000_000));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\ScriptRun\Channel;

use App\ScriptRun\Channel\ScriptChild;

final class FakeChild implements ScriptChild
{
    public bool $running = true;

    public function isRunning(): bool
    {
        return $this->running;
    }
}

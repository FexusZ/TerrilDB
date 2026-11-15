<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

final readonly class ConnectionEncryption
{
    public function __construct(
        public bool $encrypted,
        public ?string $protocol = null,
        public ?string $cipher = null,
    ) {
    }
}

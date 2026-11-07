<?php

declare(strict_types=1);

namespace App\Core\Schema\Change\Operation;

final readonly class DropTable
{
    public function __construct(public string $name)
    {
    }
}

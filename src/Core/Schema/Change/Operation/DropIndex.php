<?php

declare(strict_types=1);

namespace App\Core\Schema\Change\Operation;

use App\Core\Schema\IndexDefinition;

final readonly class DropIndex
{
    public function __construct(public string $name, public ?IndexDefinition $old = null)
    {
    }
}

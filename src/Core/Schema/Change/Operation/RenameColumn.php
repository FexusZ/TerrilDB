<?php

declare(strict_types=1);

namespace App\Core\Schema\Change\Operation;

final readonly class RenameColumn
{
    public function __construct(public string $from, public string $to)
    {
    }
}

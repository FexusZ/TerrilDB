<?php

declare(strict_types=1);

namespace App\Core\Schema\Change\Operation;

use App\Core\Schema\Change\IndexDraft;

final readonly class AddIndex
{
    public function __construct(public IndexDraft $index)
    {
    }
}

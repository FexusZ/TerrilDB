<?php

declare(strict_types=1);

namespace App\Core\Schema\Change\Operation;

use App\Core\Schema\Change\IndexDraft;
use App\Core\Schema\IndexDefinition;

final readonly class SetPrimaryKey
{
    public function __construct(public ?IndexDefinition $old, public ?IndexDraft $new)
    {
    }
}

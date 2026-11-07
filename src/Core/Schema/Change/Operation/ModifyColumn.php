<?php

declare(strict_types=1);

namespace App\Core\Schema\Change\Operation;

use App\Core\Schema\Change\ColumnDraft;
use App\Core\Schema\ColumnDefinition;

final readonly class ModifyColumn
{
    public function __construct(public ColumnDefinition $before, public ColumnDraft $after, public bool $typeChanged, public bool $nullabilityChanged, public bool $defaultChanged, public bool $autoIncrementChanged)
    {
    }
}

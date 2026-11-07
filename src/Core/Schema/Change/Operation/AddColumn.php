<?php

declare(strict_types=1);

namespace App\Core\Schema\Change\Operation;

use App\Core\Schema\Change\ColumnDraft;

final readonly class AddColumn
{
    public function __construct(public ColumnDraft $column)
    {
    }
}

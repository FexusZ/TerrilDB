<?php

declare(strict_types=1);

namespace App\Core\Schema\Change\Operation;

use App\Core\Schema\Change\ForeignKeyDraft;

final readonly class AddForeignKey
{
    public function __construct(public ForeignKeyDraft $foreignKey)
    {
    }
}

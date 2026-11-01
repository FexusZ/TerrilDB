<?php

declare(strict_types=1);

namespace App\Core\Schema;

enum TableKind: string
{
    case Table = 'table';
    case View = 'view';
    case MaterializedView = 'materialized_view';
}

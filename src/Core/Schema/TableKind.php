<?php

declare(strict_types=1);

namespace App\Core\Schema;

enum TableKind: string
{
    case Table = 'table';
    case View = 'view';
    case MaterializedView = 'materialized_view';
    /** Objet MariaDB (CREATE SEQUENCE) : ni table ni vue, lecture seule. */
    case Sequence = 'sequence';
}

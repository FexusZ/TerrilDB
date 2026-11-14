<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

enum LockKind: string
{
    /** Verrou de lignes, de table ou de transaction tenu par le moteur de stockage. */
    case Row = 'row';
    /** Verrou de métadonnées : la file derrière un DDL. */
    case Metadata = 'metadata';
}

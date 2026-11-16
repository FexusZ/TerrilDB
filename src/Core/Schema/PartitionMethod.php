<?php

declare(strict_types=1);

namespace App\Core\Schema;

/**
 * Comment une table est découpée. Les variantes « COLUMNS » et « LINEAR » de MySQL / MariaDB rentrent dans leur famille : l'expression
 * rendue par le moteur dit déjà de quelles colonnes il s'agit. KEY n'existe que sur MySQL et MariaDB.
 */
enum PartitionMethod: string
{
    case Range = 'range';
    case List = 'list';
    case Hash = 'hash';
    case Key = 'key';
}

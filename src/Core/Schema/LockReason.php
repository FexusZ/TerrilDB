<?php

declare(strict_types=1);

namespace App\Core\Schema;

/**
 * Pourquoi une colonne ou un index ne se redéfinit pas : le moteur le porte avec une particularité que le modèle ne sait pas
 * redire. L'éditeur le laisse lire, renommer et supprimer, mais refuse de le redéfinir (ce serait le dégrader en silence).
 * Les valeurs sont des codes stables, repris par l'interface.
 */
enum LockReason: string
{
    case Generated = 'generated';
    case ColumnCheck = 'column_check';
    case IndexInvisible = 'index_invisible';
    case IndexIgnored = 'index_ignored';
    case Fulltext = 'fulltext';
    case Spatial = 'spatial';
    case Prefix = 'prefix';
    case Descending = 'descending';
    case Expression = 'expression';
}

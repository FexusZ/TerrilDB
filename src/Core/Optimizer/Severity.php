<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

enum Severity: string
{
    /** À corriger : ralentit des requêtes ou rend la table difficile à utiliser. */
    case Warning = 'warning';
    /** À savoir : un gain possible, sans urgence. */
    case Info = 'info';
}

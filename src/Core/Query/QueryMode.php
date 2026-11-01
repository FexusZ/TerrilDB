<?php

declare(strict_types=1);

namespace App\Core\Query;

/**
 * Ce qu'une exécution a le droit de faire. La lecture seule est le mode par défaut ; l'API et le MCP ne
 * connaissent que celui-là.
 */
enum QueryMode: string
{
    case ReadOnly = 'read_only';
    case ReadWrite = 'read_write';
}

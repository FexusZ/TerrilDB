<?php

declare(strict_types=1);

namespace App\Audit;

/**
 * Par quelle porte l'action est arrivée.
 */
enum AuditActor: string
{
    case Web = 'web';
    case Api = 'api';
    case Mcp = 'mcp';
    case System = 'system';
}

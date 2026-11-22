<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Vitess;

use App\Core\Diagnostics\DiagnosticStatus;

/**
 * Les migrations lues (les plus récentes d'abord), bornées : `truncated` dit que le serveur en avait davantage.
 */
final readonly class MigrationReport
{
    /**
     * @param list<VitessMigration> $migrations
     */
    public function __construct(public DiagnosticStatus $status, public array $migrations, public bool $truncated = false)
    {
    }
}

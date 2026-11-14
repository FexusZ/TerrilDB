<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Log;

use App\Core\Diagnostics\DiagnosticStatus;

final readonly class SlowQueryReport
{
    /**
     * @param list<SlowQuery> $queries les plus récentes d'abord
     */
    public function __construct(
        public DiagnosticStatus $status,
        public array $queries,
        public bool $truncated,
        /** Seuil à partir duquel le serveur journalise (`long_query_time`), en secondes ; nul s'il ne le dit pas. */
        public ?float $thresholdSeconds,
    ) {
    }
}

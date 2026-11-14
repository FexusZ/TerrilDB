<?php

declare(strict_types=1);

namespace App\Core\Query\Script;

use App\Core\Guard\ScriptReport;

final readonly class ScriptPreview
{
    public function __construct(public ScriptReport $report, public ScriptCapabilities $capabilities)
    {
    }
}

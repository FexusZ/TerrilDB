<?php

declare(strict_types=1);

namespace App\Audit;

use App\Core\Query\Script\ScriptResult;
use Symfony\Component\Uid\Uuid;

final readonly class AuditedScriptRun
{
    public function __construct(public Uuid $executionId, public ScriptResult $result)
    {
    }
}

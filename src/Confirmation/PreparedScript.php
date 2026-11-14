<?php

declare(strict_types=1);

namespace App\Confirmation;

use App\Core\Query\Script\ScriptPreview;

final readonly class PreparedScript
{
    public function __construct(public ScriptPreview $preview, public string $decision, public IssuedConfirmation $confirmation)
    {
    }
}

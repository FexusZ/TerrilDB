<?php

declare(strict_types=1);

namespace App\Confirmation;

enum ConfirmationKind: string
{
    case Statement = 'statement';
    case Script = 'script';
}

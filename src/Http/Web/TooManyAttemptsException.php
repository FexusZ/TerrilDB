<?php

declare(strict_types=1);

namespace App\Http\Web;

final class TooManyAttemptsException extends \RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Too many attempts.');
    }
}

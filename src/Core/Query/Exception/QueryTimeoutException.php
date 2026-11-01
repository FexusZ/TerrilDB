<?php

declare(strict_types=1);

namespace App\Core\Query\Exception;

final class QueryTimeoutException extends \RuntimeException
{
    public static function afterMilliseconds(int $timeoutMs, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('The query was stopped after %d ms.', $timeoutMs), previous: $previous);
    }
}

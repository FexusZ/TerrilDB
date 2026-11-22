<?php

declare(strict_types=1);

namespace App\Core\Query\Exception;

use App\Core\Diagnostics\Vitess\StreamHit;
use App\Core\Guard\GuardReason;

/**
 * L'instruction est destructive : elle ne part que si l'appelant la renvoie avec une confirmation explicite.
 */
final class QueryNeedsConfirmationException extends QueryNotAllowedException
{
    /**
     * @param list<StreamHit> $streams Vitess : les flux dont une table touchée est la source (motif `stream_source`)
     */
    public function __construct(GuardReason $reason, public readonly array $streams = [])
    {
        parent::__construct($reason);
    }
}

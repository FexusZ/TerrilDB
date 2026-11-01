<?php

declare(strict_types=1);

namespace App\Core\Query\Exception;

/**
 * L'instruction est destructive : elle ne part que si l'appelant la renvoie avec une confirmation explicite.
 */
final class QueryNeedsConfirmationException extends QueryNotAllowedException
{
}

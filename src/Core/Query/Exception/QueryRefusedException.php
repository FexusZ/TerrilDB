<?php

declare(strict_types=1);

namespace App\Core\Query\Exception;

/**
 * Refus définitif : aucune confirmation n'y change rien.
 */
final class QueryRefusedException extends QueryNotAllowedException
{
}

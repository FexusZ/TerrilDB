<?php

declare(strict_types=1);

namespace App\Core\Data\Exception;

/**
 * Une ligne n'est plus celle que le client a lue (modifiée ou supprimée entre-temps) ou n'est pas retrouvée de façon unique :
 * rien n'est écrit (ou tout est annulé).
 */
final class DataChangeConflictException extends \RuntimeException
{
    public function __construct(public readonly int $statementIndex, public readonly int $matched)
    {
        parent::__construct('A row changed since it was read, or cannot be told apart from another.');
    }
}

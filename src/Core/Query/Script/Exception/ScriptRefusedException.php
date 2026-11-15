<?php

declare(strict_types=1);

namespace App\Core\Query\Script\Exception;

use App\Core\Guard\GuardReason;

/**
 * Refus définitif d'UNE instruction du script (`statementIndex`, rang à partir de 0) : aucune confirmation n'y change
 * rien, et rien n'est parti vers la cible.
 */
final class ScriptRefusedException extends \DomainException
{
    public function __construct(public readonly GuardReason $reason, public readonly int $statementIndex)
    {
        parent::__construct(\sprintf('Statement %d of the script was refused (%s); nothing was run.', $statementIndex + 1, $reason->value));
    }
}

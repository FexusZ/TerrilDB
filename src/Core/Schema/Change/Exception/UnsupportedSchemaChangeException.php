<?php

declare(strict_types=1);

namespace App\Core\Schema\Change\Exception;

use App\Core\Schema\LockReason;

/**
 * Modification refusée avant d'avoir rien écrit parce qu'elle redéfinirait une colonne ou un index porteur d'une particularité
 * que le modèle ne sait pas redire (colonne générée, index invisible…) : la modifier la dégraderait en silence.
 */
final class UnsupportedSchemaChangeException extends \DomainException
{
    public function __construct(public readonly LockReason $reason, public readonly string $subject)
    {
        parent::__construct(\sprintf('"%s" carries a definition that cannot be restated (%s): the change is not supported.', $subject, $reason->value));
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Schema\Change\Exception;

/**
 * Modification refusée avant d'avoir rien écrit (sœur de `InvalidSchemaChangeException`, qui est finale) : elle casserait le partitionnement de la table (ou le moteur la refuserait avec une
 * erreur obscure). `reason` est stable (`partition_column`, `partition_unique_key`, `partition_foreign_key`), `subject` nomme
 * la colonne, la clé ou la contrainte en cause.
 */
final class UnsupportedPartitionChangeException extends \DomainException
{
    public function __construct(public readonly string $reason, public readonly ?string $subject = null)
    {
        parent::__construct(\sprintf('This change is not supported on a partitioned table (%s).', $reason));
    }
}

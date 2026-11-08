<?php

declare(strict_types=1);

namespace App\Core\Data\Exception;

use App\Core\Data\DataStatement;

/**
 * Le moteur a refusé une instruction. `applied` : combien sont DÉJÀ en place (0 sous transaction : tout a été annulé).
 * Le message est celui du moteur : il parle des données de l'utilisateur, pas de sa connexion.
 */
final class DataChangeFailedException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $applied, public readonly DataStatement $failed, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Schema\Change\Exception;

/**
 * Modification de structure refusée avant d'avoir rien écrit : nom, type, valeur par défaut ou combinaison invalide.
 * Le message ne cite jamais la valeur reçue en entier (elle peut venir d'une saisie hasardeuse), seulement ce qui ne va pas.
 */
final class InvalidSchemaChangeException extends \DomainException
{
}

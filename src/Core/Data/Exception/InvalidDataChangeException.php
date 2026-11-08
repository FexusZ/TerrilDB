<?php

declare(strict_types=1);

namespace App\Core\Data\Exception;

/**
 * Modification de données refusée avant d'avoir rien écrit : colonne inconnue, ligne mal identifiée, brouillon vide ou trop gros.
 * Le message ne cite jamais une valeur reçue, seulement ce qui ne va pas.
 */
final class InvalidDataChangeException extends \DomainException
{
}

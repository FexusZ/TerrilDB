<?php

declare(strict_types=1);

namespace App\Core\Schema\Change\Exception;

/**
 * La table n'est plus celle que l'utilisateur a chargée : quelqu'un (ou un autre onglet) l'a modifiée entre-temps. On refuse
 * d'appliquer un brouillon construit sur une structure périmée.
 */
final class StaleSchemaException extends \RuntimeException
{
}

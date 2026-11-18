<?php

declare(strict_types=1);

namespace App\ScriptRun\Exception;

/**
 * Message de lancement refusé. Le texte ne cite JAMAIS ce qui a été reçu : le message porte des identifiants de connexion.
 */
final class InvalidScriptRunRequestException extends \DomainException
{
}

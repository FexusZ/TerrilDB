<?php

declare(strict_types=1);

namespace App\Audit\Exception;

/**
 * Un événement d'audit mal formé : défaut de programmation (détail qui ressemble à un secret, clé mal nommée), pas
 * une erreur d'exécution. Il n'est jamais avalé.
 */
final class InvalidAuditEventException extends \LogicException
{
}

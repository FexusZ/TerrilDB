<?php

declare(strict_types=1);

namespace App\Core\Schema\Exception;

/**
 * Description de schéma incohérente (nom vide, index sans colonne…) : signale un défaut d'introspection.
 */
final class InvalidSchemaException extends \DomainException
{
}

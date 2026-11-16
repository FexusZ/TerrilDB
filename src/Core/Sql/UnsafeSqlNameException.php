<?php

declare(strict_types=1);

namespace App\Core\Sql;

/**
 * Un nom venu d'une base cible qu'on ne cite pas dans du SQL montré : caractère de contrôle, séparateur de ligne,
 * texte invalide ou démesuré. Le message ne reprend jamais le nom.
 */
final class UnsafeSqlNameException extends \InvalidArgumentException
{
}

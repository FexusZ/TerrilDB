<?php

declare(strict_types=1);

namespace App\Connection;

use App\Entity\DatabaseConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Contexte authentifié des paramètres chiffrés d'une connexion : il lie le chiffré à sa ligne (un chiffré
 * recopié sur une autre connexion ne se déchiffre pas) et sert d'identifiant lors de la rotation au
 * changement de mot de passe (SecretRotator).
 */
final readonly class ConnectionSecretContext
{
    public static function forConnection(DatabaseConnection $connection): string
    {
        return self::forId($connection->getId());
    }

    public static function forId(Uuid $id): string
    {
        return 'connection:'.$id->toRfc4122();
    }
}

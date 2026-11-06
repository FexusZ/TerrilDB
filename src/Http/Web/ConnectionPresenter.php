<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Core\Connection\ConnectionParameters;
use App\Entity\DatabaseConnection;

final class ConnectionPresenter
{
    /**
     * Ce qui est lisible sans la clé : jamais un paramètre de connexion.
     *
     * @return array<string, string|null>
     */
    public static function summary(DatabaseConnection $connection): array
    {
        return [
            'id' => $connection->getId()->toRfc4122(),
            'name' => $connection->getName(),
            'engine' => $connection->getEngine()->value,
            'color' => $connection->getColor()?->value,
            'createdAt' => $connection->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $connection->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }

    /**
     * Les paramètres pour pré-remplir le formulaire de modification : le mot de passe n'est jamais renvoyé.
     *
     * @return array<string, bool|int|string>
     */
    public static function parameters(ConnectionParameters $parameters): array
    {
        return [
            'host' => $parameters->host,
            'port' => $parameters->port,
            'database' => $parameters->database,
            'username' => $parameters->username,
            'hasPassword' => '' !== $parameters->password(),
        ];
    }
}

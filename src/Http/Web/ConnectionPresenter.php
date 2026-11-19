<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\SshJump;
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
     * @return array<string, mixed>
     */
    public static function parameters(ConnectionParameters $parameters): array
    {
        $shown = [
            'host' => $parameters->host,
            'port' => $parameters->port,
            'database' => $parameters->database,
            'username' => $parameters->username,
            'hasPassword' => '' !== $parameters->password(),
        ];
        if ($parameters->readReplica) {
            $shown['readReplica'] = true;
        }
        if ($parameters->transport instanceof SshJump) {
            // Jamais la clé privée : seulement qu'il y en a une. L'empreinte est publique.
            $shown['transport'] = [
                'type' => 'ssh',
                'host' => $parameters->transport->host,
                'port' => $parameters->transport->port,
                'username' => $parameters->transport->username,
                'hasPrivateKey' => true,
                'hostKeyFingerprint' => $parameters->transport->hostKeyFingerprint,
            ];
        }

        return $shown;
    }
}

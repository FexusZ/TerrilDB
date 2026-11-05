<?php

declare(strict_types=1);

namespace App\Driver\Redis;

use App\Core\Connection\ConnectionParameters;
use Predis\Client;

/**
 * Prépare un client Predis pour une base Redis. La « base » des paramètres de connexion est le numéro de base (0 à 255),
 * l'identifiant est l'utilisateur ACL (« default » sans ACL). Le port 6380 parle TLS (usage courant ; pas d'option dédiée).
 * Le client se connecte au premier ordre : l'ouverture ne vérifie rien (c'est le driver qui envoie un PING).
 */
final readonly class RedisConnector
{
    private const CONNECT_TIMEOUT_SECONDS = 5.0;
    private const TLS_PORTS = [6380];
    private const MAX_DATABASE = 255;

    public function open(ConnectionParameters $parameters, float $readWriteTimeout = 30.0): Client
    {
        if (1 !== preg_match('/^\d{1,3}$/D', $parameters->database) || (int) $parameters->database > self::MAX_DATABASE) {
            throw new \InvalidArgumentException('The Redis database must be a number between 0 and 255.');
        }

        $options = [
            'scheme' => \in_array($parameters->port, self::TLS_PORTS, true) ? 'tls' : 'tcp',
            'host' => $parameters->address(),
            'port' => $parameters->port,
            'username' => $parameters->username,
            'password' => $parameters->password(),
            'database' => (int) $parameters->database,
            'timeout' => self::CONNECT_TIMEOUT_SECONDS,
            'read_write_timeout' => $readWriteTimeout,
        ];
        if ('tls' === $options['scheme']) {
            // On se connecte à l'adresse contrôlée, mais le certificat se vérifie contre le nom saisi.
            $options['ssl'] = ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $parameters->host];
        }

        return new Client($options);
    }
}

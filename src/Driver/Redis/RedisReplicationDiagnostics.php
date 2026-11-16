<?php

declare(strict_types=1);

namespace App\Driver\Redis;

use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\Exception\DiagnosticsFailedException;
use App\Core\Diagnostics\Replication\ReplicationReport;
use App\Core\Diagnostics\ReplicationDiagnostics;
use Predis\Client;
use Predis\PredisException;

/**
 * Réplication de Redis par `INFO replication`, lu par l'interface de diagnostics et non par la console : une seule commande fixe,
 * en lecture. Un compte ACL sans `INFO` (NOPERM) est un état « droits insuffisants ». Voir RedisReplicationReport pour ce qui n'est
 * jamais renvoyé (hôte, port et mot de passe de la source, adresses des réplicas).
 */
final readonly class RedisReplicationDiagnostics implements ReplicationDiagnostics
{
    private const PRIVILEGE = 'INFO';

    public function __construct(private Client $client)
    {
    }

    public function status(): DiagnosticStatus
    {
        $info = $this->read();

        return $info instanceof DiagnosticStatus ? $info : DiagnosticStatus::available();
    }

    public function replication(): ReplicationReport
    {
        $info = $this->read();

        return $info instanceof DiagnosticStatus ? ReplicationReport::unreadable($info) : RedisReplicationReport::build($info);
    }

    private function read(): string|DiagnosticStatus
    {
        try {
            $error = false;
            $reply = $this->client->executeRaw(['INFO', 'replication'], $error);
        } catch (PredisException $e) {
            throw DiagnosticsFailedException::create($e);
        }

        if ($error) {
            if (\is_string($reply) && str_starts_with($reply, 'NOPERM')) {
                return DiagnosticStatus::denied(self::PRIVILEGE);
            }

            throw DiagnosticsFailedException::create(new \RuntimeException('INFO replication was refused.'));
        }

        return \is_string($reply) ? $reply : throw DiagnosticsFailedException::create(new \UnexpectedValueException('INFO replication returned no text.'));
    }
}

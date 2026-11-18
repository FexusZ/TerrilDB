<?php

declare(strict_types=1);

namespace App\ScriptRun\Channel;

use App\ScriptRun\ScriptRunLauncher;
use App\ScriptRun\ScriptRunRequest;

/**
 * Le côté de la requête web : envoie le message au worker par le socket Unix du volume partagé et attend son accord. Rien n'est
 * écrit ailleurs : les identifiants du message ne passent que par ce socket.
 */
final readonly class ScriptRunDispatcher implements ScriptRunLauncher
{
    private const CONNECT_TIMEOUT_SECONDS = 2;
    private const REPLY_TIMEOUT_SECONDS = 5;

    public function __construct(private string $socketPath, private ScriptRunChannel $channel)
    {
    }

    /**
     * @throws ScriptWorkerUnavailableException le worker ne répond pas ou refuse : l'exécution n'a pas démarré
     * @throws ScriptWorkerBusyException        le worker joue déjà le nombre maximal d'exécutions
     */
    public function dispatch(ScriptRunRequest $request): void
    {
        $stream = @stream_socket_client('unix://'.$this->socketPath, $errno, $error, self::CONNECT_TIMEOUT_SECONDS);
        if (false === $stream) {
            throw new ScriptWorkerUnavailableException('The script worker cannot be reached.');
        }

        try {
            stream_set_timeout($stream, self::REPLY_TIMEOUT_SECONDS);
            $frame = $this->channel->frame($request->toJson());
            for ($written = 0; $written < \strlen($frame);) {
                $bytes = @fwrite($stream, substr($frame, $written));
                if (false === $bytes || 0 === $bytes) {
                    throw new ScriptWorkerUnavailableException('The script worker closed the connection.');
                }
                $written += $bytes;
            }
            $reply = trim((string) fgets($stream, 64));
        } finally {
            fclose($stream);
        }

        match ($reply) {
            'ok' => null,
            'busy' => throw new ScriptWorkerBusyException('The script worker is busy.'),
            default => throw new ScriptWorkerUnavailableException('The script worker did not accept the run.'),
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Tunnel;

use App\Core\Connection\Exception\ForbiddenBastionException;
use App\Core\Connection\Tunnel\TunnelEndpoint;
use App\Core\Connection\Tunnel\TunnelFailedException;
use App\Core\Connection\Tunnel\TunnelOpener;
use App\Core\Connection\Tunnel\TunnelRequest;

/**
 * Parle au conteneur du tunnel par son socket de contrôle (un dossier `0700` partagé par un volume) : une ligne de
 * JSON par demande, une ligne par réponse. La clé privée voyage dans ce flux et nulle part ailleurs : jamais dans un
 * fichier, un journal, un message d'erreur. Le détail d'un échec reste côté relais ; ici on ne garde qu'un code.
 */
final readonly class UnixSocketTunnelOpener implements TunnelOpener
{
    private const RESPONSE_MAX_BYTES = 4096;
    private const REASONS = [TunnelFailedException::HOST_KEY_UNCONFIRMED, TunnelFailedException::HOST_KEY_CHANGED, TunnelFailedException::UNAVAILABLE];

    public function __construct(
        private string $controlSocket,
        private int $timeoutSeconds = 20,
    ) {
    }

    public function open(TunnelRequest $request): TunnelEndpoint
    {
        $reply = $this->call([
            'op' => 'open',
            'host' => $request->jump->host,
            'port' => $request->jump->port,
            'user' => $request->jump->username,
            'private_key' => $request->jump->privateKey(),
            'fingerprint' => $request->jump->hostKeyFingerprint,
            'target_host' => $request->targetHost,
            'target_port' => $request->targetPort,
            'socket_name' => $request->socketName,
            'idle_seconds' => $request->idleSeconds,
        ]);

        if (true !== ($reply['ok'] ?? null)) {
            $error = $reply['error'] ?? null;
            if ('forbidden_bastion' === $error) {
                throw ForbiddenBastionException::create();
            }

            throw new TunnelFailedException(\is_string($error) && \in_array($error, self::REASONS, true) ? $error : TunnelFailedException::UNAVAILABLE);
        }

        $id = $reply['id'] ?? null;
        $socket = $reply['socket'] ?? null;
        $root = \dirname($this->controlSocket).'/';
        if (!\is_string($id) || 1 !== preg_match('/^[0-9a-f]{8,64}$/D', $id) || !\is_string($socket) || !str_starts_with($socket, $root) || str_contains($socket, '..') || str_contains($socket, "\0")) {
            throw new TunnelFailedException();
        }

        return new TunnelEndpoint($id, $socket);
    }

    public function close(TunnelEndpoint $endpoint): void
    {
        try {
            $this->call(['op' => 'close', 'id' => $endpoint->id]);
        } catch (TunnelFailedException) {
            // Le relais ferme de lui-même après l'inactivité : un échec ici n'a pas de suite.
        }
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     *
     * @throws TunnelFailedException
     */
    private function call(#[\SensitiveParameter] array $message): array
    {
        $stream = @stream_socket_client('unix://'.$this->controlSocket, $errno, $error, $this->timeoutSeconds);
        if (false === $stream) {
            throw new TunnelFailedException();
        }

        try {
            stream_set_timeout($stream, $this->timeoutSeconds);
            if (false === fwrite($stream, json_encode($message, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)."\n")) {
                throw new TunnelFailedException();
            }
            $line = fgets($stream, self::RESPONSE_MAX_BYTES);
        } catch (\JsonException) {
            throw new TunnelFailedException();
        } finally {
            fclose($stream);
        }

        if (false === $line) {
            throw new TunnelFailedException();
        }
        $decoded = json_decode($line, true);

        if (!\is_array($decoded)) {
            throw new TunnelFailedException();
        }
        $reply = [];
        foreach ($decoded as $key => $value) {
            $reply[(string) $key] = $value;
        }

        return $reply;
    }
}

<?php

declare(strict_types=1);

namespace App\ScriptRun\Channel;

use App\ScriptRun\Exception\InvalidScriptRunRequestException;
use App\ScriptRun\ScriptRunRequest;

/**
 * Le côté du worker : écoute le socket Unix, vérifie la signature et la forme du message, puis confie CHAQUE exécution à un
 * processus à elle (le message passe par son entrée standard). Le serveur ne joue jamais un script lui-même : une exécution qui
 * plante ou dure ne bloque ni l'écoute ni les autres. Les réponses sont `ok`, `busy` ou `refused`, sans détail.
 */
final class ScriptWorkerServer
{
    /** @var resource|null */
    private $listener;
    /** @var list<array{id: string, child: ScriptChild}> */
    private array $children = [];

    public function __construct(
        private readonly string $socketPath,
        private readonly ScriptRunChannel $channel,
        private readonly ChildSpawner $spawner,
        private readonly int $maxChildren = 4,
        /** Un client qui n'envoie rien (ou trop lentement) est lâché après ce délai. */
        private readonly int $readTimeoutSeconds = 5,
    ) {
    }

    public function listen(): void
    {
        // Un socket laissé par un worker mort empêcherait de lier le chemin.
        if (file_exists($this->socketPath)) {
            @unlink($this->socketPath);
        }
        $listener = @stream_socket_server('unix://'.$this->socketPath, $errno, $error);
        if (false === $listener) {
            throw new \RuntimeException('The worker cannot listen on its socket.');
        }
        chmod($this->socketPath, 0600);
        $this->listener = $listener;
    }

    public function close(): void
    {
        if (\is_resource($this->listener)) {
            fclose($this->listener);
        }
        $this->listener = null;
        if (file_exists($this->socketPath)) {
            @unlink($this->socketPath);
        }
    }

    public function liveChildren(): int
    {
        $this->reap();

        return \count($this->children);
    }

    /**
     * Les exécutions que ses processus jouent encore.
     *
     * @return list<string> identifiants d'exécution
     */
    public function liveRunIds(): array
    {
        $this->reap();

        return array_map(static fn (array $entry): string => $entry['id'], $this->children);
    }

    /**
     * Un tour : attend au plus `$waitMs` une connexion, la traite, puis libère les processus terminés.
     */
    public function tick(int $waitMs = 1000): void
    {
        $this->reap();
        if (!\is_resource($this->listener)) {
            return;
        }
        $read = [$this->listener];
        $write = $except = null;
        $ready = @stream_select($read, $write, $except, intdiv($waitMs, 1000), ($waitMs % 1000) * 1000);
        if (\is_int($ready) && $ready > 0) {
            $client = @stream_socket_accept($this->listener, 0);
            if (false !== $client) {
                try {
                    $this->handle($client);
                } finally {
                    fclose($client);
                }
            }
        }
        $this->reap();
    }

    /**
     * @param resource $client
     */
    private function handle($client): void
    {
        stream_set_timeout($client, $this->readTimeoutSeconds);
        $frame = $this->readFrame($client);
        $json = null === $frame ? null : $this->channel->open($frame);
        if (null === $json) {
            $this->reply($client, 'refused');

            return;
        }
        try {
            $request = ScriptRunRequest::fromJson($json);
        } catch (InvalidScriptRunRequestException) {
            $this->reply($client, 'refused');

            return;
        }
        $this->reap();
        if (\count($this->children) >= $this->maxChildren) {
            $this->reply($client, 'busy');

            return;
        }
        try {
            $child = $this->spawner->spawn($json);
        } catch (\Throwable) {
            $this->reply($client, 'refused');

            return;
        }
        $this->children[] = ['id' => $request->runId->toRfc4122(), 'child' => $child];
        $this->reply($client, 'ok');
    }

    /**
     * Lit « signature, saut de ligne, JSON, saut de ligne », au plus la taille permise ; null si le message est trop gros, coupé ou muet.
     *
     * @param resource $client
     */
    private function readFrame($client): ?string
    {
        $limit = ScriptRunRequest::MAX_JSON_BYTES + 200;
        $frame = '';
        $newlines = 0;
        while ($newlines < 2) {
            $line = fgets($client, max(1, $limit - \strlen($frame) + 1));
            if (false === $line) {
                return null;
            }
            $frame .= $line;
            if (str_ends_with($line, "\n")) {
                ++$newlines;
            }
            if (\strlen($frame) >= $limit && $newlines < 2) {
                return null;
            }
        }

        return $frame;
    }

    /**
     * @param resource $client
     */
    private function reply($client, string $word): void
    {
        @fwrite($client, $word."\n");
    }

    private function reap(): void
    {
        $this->children = array_values(array_filter($this->children, static fn (array $entry): bool => $entry['child']->isRunning()));
    }
}

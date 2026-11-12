<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

/**
 * Lance une instruction dans un SOUS-PROCESSUS PHP (PDO est synchrone : le test ne peut pas attendre un verrou et continuer dans
 * le même processus), avec un délai de verrou court. Toujours finir par `stop()` dans un `finally` : il tue le processus ET coupe
 * la session côté serveur, pour qu'aucune attente ne reste en file.
 */
final class BlockedStatement
{
    /** @var resource|null */
    private $process;
    /** @var resource|null */
    private $stdout;
    private string $buffer = '';
    private readonly float $startedAt;

    /**
     * @param resource $process
     * @param resource $stdout
     */
    private function __construct(private readonly string $env, $process, $stdout, private int $id)
    {
        $this->process = $process;
        $this->stdout = $stdout;
        $this->startedAt = microtime(true);
    }

    /** Démarre le sous-processus et attend qu'il ait ouvert sa transaction (la ligne « ready »). */
    public static function start(string $env, string $sql, int $lockTimeoutSeconds = 1): self
    {
        $command = [\PHP_BINARY, __DIR__.'/waiter.php', $env, (string) $lockTimeoutSeconds, $sql];
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Cannot start the waiting process.');
        }
        $self = new self($env, $process, $pipes[1], 0);
        try {
            $line = $self->readLine(10.0);
            if (null === $line || !str_starts_with($line, 'ready ')) {
                throw new \RuntimeException('The waiting process did not become ready: '.($line ?? 'no output'));
            }
            $self->id = (int) substr($line, 6);
        } catch (\Throwable $e) {
            $self->stop();
            throw $e;
        }

        return $self;
    }

    /** Identifiant de session côté serveur du sous-processus. */
    public function backendId(): int
    {
        return $this->id;
    }

    public function isRunning(): bool
    {
        return null !== $this->process && proc_get_status($this->process)['running'];
    }

    /** Attend la fin de l'instruction (au plus `$timeout` s, sinon échec du test) et rend son issue. */
    public function result(float $timeout = 20.0): BlockedResult
    {
        $line = $this->readLine($timeout);
        if (null === $line) {
            throw new \RuntimeException('The waiting statement did not finish in time.');
        }
        $seconds = microtime(true) - $this->startedAt;
        if ('ok' === $line) {
            return new BlockedResult(true, '', '', $seconds);
        }
        [, $state, $code] = [...explode(' ', $line, 3), '', ''];

        return new BlockedResult(false, $state, $code, $seconds);
    }

    /** Tue le sous-processus, coupe sa session côté serveur et ferme les tubes ; sans effet si déjà fait. */
    public function stop(): void
    {
        if (null === $this->process) {
            return;
        }
        proc_terminate($this->process, 9);
        proc_close($this->process);
        if (\is_resource($this->stdout)) {
            fclose($this->stdout);
        }
        $this->process = $this->stdout = null;
        if ($this->id > 0) {
            BlockingSession::kill($this->env, $this->id);
        }
    }

    private function readLine(float $timeout): ?string
    {
        $deadline = microtime(true) + $timeout;
        while (!str_contains($this->buffer, "\n")) {
            $left = $deadline - microtime(true);
            if ($left <= 0 || !\is_resource($this->stdout)) {
                return null;
            }
            $read = [$this->stdout];
            $write = $except = null;
            $ready = stream_select($read, $write, $except, (int) $left, (int) (fmod($left, 1.0) * 1_000_000));
            if (false === $ready) {
                return null;
            }
            if (0 === $ready) {
                continue;
            }
            $chunk = fread($this->stdout, 4096);
            if (false === $chunk || '' === $chunk) {
                if (feof($this->stdout)) {
                    return '' === $this->buffer ? null : $this->takeLine();
                }
                continue;
            }
            $this->buffer .= $chunk;
        }

        return $this->takeLine();
    }

    private function takeLine(): string
    {
        $pos = strpos($this->buffer, "\n");
        $line = false === $pos ? $this->buffer : substr($this->buffer, 0, $pos);
        $this->buffer = false === $pos ? '' : substr($this->buffer, $pos + 1);

        return $line;
    }
}

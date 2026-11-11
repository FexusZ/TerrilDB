<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Driver\DbalConnector;
use Doctrine\DBAL\Connection;

/**
 * Un vrai blocage sur un vrai moteur, dans une table à part (jamais le schéma partagé) : une transaction tient le verrou d'une
 * ligne ou d'une table, une seconde attend dans un sous-processus (PDO est synchrone). `cleanup()` rend tout, y compris sur échec.
 */
final class LockScenario
{
    private ?Connection $holder = null;
    /** @var resource|null */
    private mixed $process = null;
    /** @var array<int, resource> */
    private array $pipes = [];
    private string $waiterId = '';

    private function __construct(
        public readonly string $envVar,
        public readonly string $table,
        private readonly Engine $engine,
    ) {
    }

    /**
     * Crée la table et ouvre la transaction qui verrouille la ligne 1 (`update`) ou lit la table (`read` : verrou de métadonnées).
     */
    public static function begin(string $envVar, string $holds = 'update'): self
    {
        $engine = TargetDatabase::engine($envVar);
        $scenario = new self($envVar, 'tdb_lock_'.bin2hex(random_bytes(4)), $engine);
        $admin = self::connect($envVar);
        $admin->executeStatement(\sprintf('CREATE TABLE %s (id INT PRIMARY KEY, v INT NOT NULL)', $scenario->table));
        $admin->executeStatement(\sprintf('INSERT INTO %s VALUES (1, 0), (2, 0)', $scenario->table));
        $admin->close();

        $holder = self::connect($envVar);
        $scenario->holder = $holder;
        $holder->executeStatement(Engine::PostgreSql === $engine ? 'BEGIN' : 'START TRANSACTION');
        if ('update' === $holds) {
            $holder->executeStatement(\sprintf('UPDATE %s SET v = v + 1 WHERE id = 1', $scenario->table));
        } else {
            $holder->fetchAllAssociative(\sprintf('SELECT * FROM %s', $scenario->table));
        }

        return $scenario;
    }

    private static function connect(string $envVar): Connection
    {
        return (new DbalConnector())->connect(TargetDatabase::engine($envVar), TargetDatabase::parameters($envVar));
    }

    /** L'identifiant de session de celui qui tient le verrou. */
    public function holderId(): string
    {
        $id = $this->holder?->fetchOne(Engine::PostgreSql === $this->engine ? 'SELECT pg_backend_pid()' : 'SELECT CONNECTION_ID()');

        return (string) (\is_scalar($id) ? $id : '');
    }

    public function waiterId(): string
    {
        return $this->waiterId;
    }

    /**
     * Lance, dans un autre processus, l'instruction qui va attendre (patron `%s` = la table).
     */
    public function startWaiter(string $sqlTemplate = 'UPDATE %s SET v = v + 10 WHERE id = 1'): void
    {
        $this->process = proc_open(
            [\PHP_BINARY, __DIR__.'/lock_waiter.php', $this->envVar, \sprintf($sqlTemplate, $this->table)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $this->pipes,
        );
        if (!\is_resource($this->process)) {
            throw new \RuntimeException('Cannot start the waiter.');
        }
        $line = fgets($this->pipes[1]);
        $this->waiterId = false === $line ? '' : trim($line);
        if ('' === $this->waiterId) {
            throw new \RuntimeException('The waiter did not connect.');
        }
    }

    /**
     * Attend (au plus 10 s) que `$ready` soit vrai : le temps que le sous-processus se mette en attente côté serveur.
     *
     * @template T
     *
     * @param \Closure(): T     $read
     * @param \Closure(T): bool $ready
     *
     * @return T
     */
    public static function until(\Closure $read, \Closure $ready): mixed
    {
        $deadline = microtime(true) + 10;
        while (true) {
            $value = $read();
            if ($ready($value) || microtime(true) > $deadline) {
                return $value;
            }
            usleep(200_000);
        }
    }

    public function release(): void
    {
        try {
            $this->holder?->executeStatement('ROLLBACK');
        } catch (\Throwable) {
        }
        if (\is_resource($this->process)) {
            foreach ($this->pipes as $pipe) {
                if (\is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->process);
            $this->process = null;
        }
    }

    public function cleanup(): void
    {
        $this->release();
        $this->holder?->close();
        $this->holder = null;
        $admin = self::connect($this->envVar);
        $admin->executeStatement(\sprintf('DROP TABLE IF EXISTS %s', $this->table));
        $admin->close();
    }
}

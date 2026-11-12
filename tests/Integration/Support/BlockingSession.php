<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use App\Core\Connection\Engine;
use App\Tests\Integration\Driver\LockPeer;
use App\Tests\Integration\Driver\TargetDatabase;

/**
 * Une seconde connexion, hors de la transaction que le test peut annuler : elle tient un verrou (FOR UPDATE, DDL en attente…)
 * le temps qu'il faut, puis le rend. Toujours passer par `hold()` (libération dans un `finally`) ou appeler `release()` soi-même.
 */
final class BlockingSession
{
    private ?\PDO $pdo;
    private readonly int $id;

    private function __construct(private readonly string $env, \PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->id = (int) self::scalar($pdo, self::isPostgres($env) ? 'SELECT pg_backend_pid()' : 'SELECT CONNECTION_ID()');
    }

    public static function open(string $env): self
    {
        return new self($env, LockPeer::connect($env));
    }

    /**
     * Ouvre une session, y lance les instructions dans une transaction (elles tiennent donc leurs verrous), appelle `$then` puis
     * libère TOUJOURS, exception comprise.
     *
     * @template T
     *
     * @param list<string>                 $statements
     * @param callable(BlockingSession): T $then
     *
     * @return T
     */
    public static function hold(string $env, array $statements, callable $then): mixed
    {
        $session = self::open($env);
        try {
            $session->begin();
            foreach ($statements as $statement) {
                $session->exec($statement);
            }

            return $then($session);
        } finally {
            $session->release();
        }
    }

    /** Identifiant de session côté serveur (CONNECTION_ID / pid). */
    public function id(): int
    {
        return $this->id;
    }

    public function begin(): self
    {
        return $this->exec('BEGIN');
    }

    public function exec(string $sql): self
    {
        $statement = ($this->pdo ?? throw new \LogicException('The session is released.'))->query($sql);
        if (false === $statement) {
            throw new \RuntimeException('The statement failed.');
        }
        if ($statement->columnCount() > 0) {
            // Un SELECT ... FOR UPDATE rend des lignes : les lire, sinon MySQL refuse la requête suivante (dont le ROLLBACK).
            $statement->fetchAll();
        }
        $statement->closeCursor();

        return $this;
    }

    /** Annule la transaction et ferme la connexion ; sans effet si déjà libérée. */
    public function release(): void
    {
        $pdo = $this->pdo;
        $this->pdo = null;
        if (null === $pdo) {
            return;
        }
        try {
            $pdo->exec('ROLLBACK');
        } catch (\PDOException) {
            // La connexion est peut-être déjà coupée : la fermeture ci-dessous suffit.
        }
        $pdo = null;
    }

    public static function exists(string $env, int $id): bool
    {
        $pdo = LockPeer::connect($env);
        if (self::isPostgres($env)) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM pg_stat_activity WHERE pid = ?');
            $stmt->execute([$id]);

            return 0 < (int) $stmt->fetchColumn();
        }
        $list = $pdo->query('SHOW PROCESSLIST');
        foreach (false === $list ? [] : $list->fetchAll(\PDO::FETCH_NUM) as $row) {
            if (\is_array($row) && \is_scalar($row[0] ?? null) && (int) $row[0] === $id) {
                return true;
            }
        }

        return false;
    }

    /** Première colonne de la première ligne, en texte (aide commune aux tests et au sous-processus). */
    public static function scalar(\PDO $pdo, string $sql): string
    {
        $statement = $pdo->query($sql);
        $value = false === $statement ? false : $statement->fetchColumn();

        return \is_scalar($value) ? (string) $value : '';
    }

    /** Attend (au plus `$seconds`) que le serveur ait fini de fermer la session ; vrai si elle a disparu. */
    public static function waitUntilGone(string $env, int $id, float $seconds = 5.0): bool
    {
        $deadline = microtime(true) + $seconds;
        do {
            if (!self::exists($env, $id)) {
                return true;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    /** Coupe côté serveur la session `$id` (le sous-processus tué ne suffit pas : un verrou en attente reste en file). */
    public static function kill(string $env, int $id): void
    {
        $pdo = LockPeer::connect($env);
        try {
            if (self::isPostgres($env)) {
                $pdo->prepare('SELECT pg_terminate_backend(?)')->execute([$id]);
            } else {
                $pdo->exec(\sprintf('KILL %d', $id));
            }
        } catch (\PDOException) {
            // Déjà partie.
        }
    }

    private static function isPostgres(string $env): bool
    {
        return Engine::PostgreSql === TargetDatabase::engine($env);
    }
}

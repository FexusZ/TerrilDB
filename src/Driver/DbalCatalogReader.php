<?php

declare(strict_types=1);

namespace App\Driver;

use App\Core\Diagnostics\Exception\DiagnosticsFailedException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Lit le catalogue d'un serveur SQL pour les diagnostics : toujours dans une transaction en lecture seule, annulée à la fin,
 * avec des requêtes fixes. Un échec du moteur devient une erreur générique (le détail reste dans l'exception précédente).
 */
final readonly class DbalCatalogReader
{
    public function __construct(private Connection $connection, private string $begin)
    {
    }

    public static function mysql(Connection $connection): self
    {
        return new self($connection, 'START TRANSACTION READ ONLY');
    }

    public static function postgresql(Connection $connection): self
    {
        return new self($connection, 'BEGIN READ ONLY');
    }

    /**
     * @template T
     *
     * @param \Closure(Connection): T $read
     *
     * @return T
     *
     * @throws DiagnosticsFailedException
     */
    public function read(\Closure $read): mixed
    {
        try {
            $this->connection->executeStatement($this->begin);
            try {
                return $read($this->connection);
            } finally {
                $this->connection->executeStatement('ROLLBACK');
            }
        } catch (DbalException $e) {
            throw DiagnosticsFailedException::create($e);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Driver\Exception;

use App\Core\Driver\EngineFault;

/**
 * La base cible n'a pas pu être jointe (injoignable, identifiants refusés, base inconnue…). Le message est
 * volontairement générique : le motif détaillé du moteur reste dans l'exception précédente, pour les journaux,
 * et ne doit jamais être montré tel quel à un utilisateur.
 */
final class TargetConnectionFailedException extends \RuntimeException
{
    public function __construct(string $message, ?\Throwable $previous = null, public readonly ?EngineFault $fault = null, public readonly bool $overlapsLocalNetwork = false)
    {
        parent::__construct($message, 0, $previous);
    }

    /** `fault` : le refus connu du serveur (serveur plein, identifiants refusés, TLS exigé), quand le code du moteur le dit. */
    public static function create(?\Throwable $previous = null, ?EngineFault $fault = null): self
    {
        return new self('Could not connect to the database.', $previous, $fault);
    }

    /**
     * L'adresse visée tombe dans un réseau relié en direct au serveur (un pont Docker) : le trafic n'en sort pas, la cible paraît donc
     * injoignable. Un indice pour l'opérateur, jamais l'adresse elle-même.
     */
    public function overlappingLocalNetwork(): self
    {
        return new self($this->getMessage(), $this->getPrevious(), $this->fault, true);
    }
}

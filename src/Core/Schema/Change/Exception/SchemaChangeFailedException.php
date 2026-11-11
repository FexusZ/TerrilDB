<?php

declare(strict_types=1);

namespace App\Core\Schema\Change\Exception;

use App\Core\Diagnostics\TableHolders;
use App\Core\Schema\Change\DdlStatement;

/**
 * Le moteur a refusé une instruction. `applied` : ce qui est DÉJÀ en place (vide quand tout a été annulé, ce que garantit une
 * transaction), `failed` : l'instruction refusée. Le message est celui du moteur : il parle de la structure de l'utilisateur,
 * pas de sa connexion. `lockTimeout` : l'attente d'un verrou a dépassé son délai (une transaction ouverte retenait la table) ;
 * `holders` : qui la tenait, quand le diagnostic a pu le lire (jamais deviné).
 */
final class SchemaChangeFailedException extends \RuntimeException
{
    /**
     * @param list<DdlStatement> $applied
     */
    public function __construct(
        string $message,
        public readonly array $applied,
        public readonly DdlStatement $failed,
        ?\Throwable $previous = null,
        public readonly bool $lockTimeout = false,
        public readonly ?TableHolders $holders = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function withHolders(TableHolders $holders): self
    {
        return new self($this->getMessage(), $this->applied, $this->failed, $this->getPrevious(), $this->lockTimeout, $holders);
    }
}

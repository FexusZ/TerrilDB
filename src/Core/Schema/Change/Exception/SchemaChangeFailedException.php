<?php

declare(strict_types=1);

namespace App\Core\Schema\Change\Exception;

use App\Core\Driver\LockConflict;
use App\Core\Schema\Change\DdlStatement;

/**
 * Le moteur a refusé une instruction. `applied` : ce qui est DÉJÀ en place (vide quand tout a été annulé, ce que garantit une
 * transaction), `failed` : l'instruction refusée. `lock` : un verrou est en cause (le message n'est alors pas montré). Le message est celui du moteur : il parle de la structure de l'utilisateur,
 * pas de sa connexion.
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
        public readonly ?LockConflict $lock = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

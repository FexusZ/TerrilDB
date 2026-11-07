<?php

declare(strict_types=1);

namespace App\Core\Schema\Change;

use App\Core\Schema\Change\Exception\SchemaChangeFailedException;

/**
 * Exécute des instructions de structure DÉJÀ générées par un dialecte (jamais du SQL venu du client). Selon le moteur, tout ou
 * rien (une transaction) ou instruction par instruction : voir `SchemaChangeFailedException::$applied`.
 */
interface DdlExecutor
{
    /**
     * @param list<DdlStatement> $statements
     *
     * @throws SchemaChangeFailedException
     */
    public function execute(array $statements, bool $transactional): void;
}

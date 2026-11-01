<?php

declare(strict_types=1);

namespace App\Core\Query;

use App\Core\Guard\StatementKind;
use App\Core\Query\Exception\QueryFailedException;
use App\Core\Query\Exception\QueryTimeoutException;

/**
 * Exécute UNE instruction déjà examinée par le garde, avec les bornes demandées. C'est ici, côté moteur, que la
 * lecture seule est réellement imposée : une transaction READ ONLY, toujours annulée à la fin. Le filtre lexical
 * du garde peut se tromper, pas le moteur.
 */
interface QueryRunner
{
    /**
     * @throws QueryFailedException
     * @throws QueryTimeoutException
     */
    public function run(string $sql, StatementKind $kind, QueryOptions $options): QueryResult;
}

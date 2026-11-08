<?php

declare(strict_types=1);

namespace App\Core\Data;

use App\Core\Data\Exception\DataChangeConflictException;
use App\Core\Data\Exception\DataChangeFailedException;

/**
 * Exécute des instructions d'écriture DÉJÀ générées par `DataChangePlanner` (jamais du SQL venu du client).
 */
interface DataExecutor
{
    /**
     * Combien de lignes retrouve la clause WHERE de l'instruction.
     *
     * @throws DataChangeFailedException
     */
    public function matching(DataStatement $statement): int;

    /**
     * Tout ou rien quand le plan est transactionnel : une modification ou une suppression qui ne touche pas exactement une ligne
     * annule tout. Sans transaction (ClickHouse), instruction par instruction et sans ce contrôle : le moteur ne le rend pas.
     *
     * @throws DataChangeConflictException
     * @throws DataChangeFailedException
     */
    public function execute(DataPlan $plan): void;
}

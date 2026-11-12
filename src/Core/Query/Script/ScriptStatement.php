<?php

declare(strict_types=1);

namespace App\Core\Query\Script;

use App\Core\Guard\StatementKind;

/**
 * Une instruction d'un script déjà jugée par le garde, telle que l'exécuteur du moteur la reçoit. `commitsImplicitly`
 * dit que le moteur valide tout ce qui précède avant de l'exécuter (DDL, CALL, DO sur MySQL et MariaDB).
 */
final readonly class ScriptStatement
{
    public function __construct(
        public int $index,
        public string $sql,
        public StatementKind $kind,
        public bool $commitsImplicitly = false,
    ) {
    }
}

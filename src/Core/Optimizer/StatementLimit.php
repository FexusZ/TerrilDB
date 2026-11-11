<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

use App\Core\Connection\Engine;
use App\Core\Guard\SqlDialect;
use App\Core\Guard\SqlScanner;

/**
 * Dit si une instruction borne ses lignes (LIMIT, FETCH). MySQL et MariaDB n'écrivent pas la limite dans leur plan :
 * un tri sous LIMIT n'y diffère pas d'un tri complet. Le mot est cherché à n'importe quelle profondeur, et un texte
 * inachevé compte comme borné : mieux vaut se taire que signaler à tort.
 */
final readonly class StatementLimit
{
    public function has(Engine $engine, string $sql): bool
    {
        $scanned = (new SqlScanner())->scan($sql, SqlDialect::forEngine($engine));
        if (!$scanned->complete) {
            return true;
        }
        foreach ($scanned->statements as $statement) {
            if ($statement->has('LIMIT') || $statement->has('FETCH')) {
                return true;
            }
        }

        return false;
    }
}

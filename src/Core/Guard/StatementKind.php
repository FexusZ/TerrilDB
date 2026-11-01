<?php

declare(strict_types=1);

namespace App\Core\Guard;

enum StatementKind
{
    /** SELECT, VALUES, TABLE, WITH sans écriture. */
    case Read;
    /** SHOW, DESCRIBE, EXPLAIN (sans exécution d'écriture). */
    case Inspect;
    /** INSERT, UPDATE, DELETE, REPLACE, MERGE, CALL, DO, ou un WITH / EXPLAIN ANALYZE qui écrit. */
    case Write;
    /** CREATE, ALTER, DROP, TRUNCATE, GRANT… */
    case Schema;
}

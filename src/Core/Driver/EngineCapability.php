<?php

declare(strict_types=1);

namespace App\Core\Driver;

/**
 * Ce qu'un serveur sait faire et que les moteurs ne partagent pas. La valeur est le nom stable exposé par l'interface.
 * La présence d'une capacité dépend du moteur ET de sa version : voir `CapabilityMatrix`.
 */
enum EngineCapability: string
{
    /** `WITH RECURSIVE`. */
    case RecursiveCte = 'recursive_cte';
    /** `JOIN LATERAL`. */
    case Lateral = 'lateral';
    /** `fonction() OVER (…)`. */
    case WindowFunctions = 'window_functions';
    /** `JSON_TABLE(…)`. */
    case JsonTable = 'json_table';
    /** Tables à historique (`WITH SYSTEM VERSIONING`, `FOR SYSTEM_TIME`). */
    case SystemVersioning = 'system_versioning';
    /** `FOR UPDATE SKIP LOCKED`. */
    case SkipLocked = 'skip_locked';
    /** `FOR UPDATE NOWAIT`. */
    case NoWait = 'no_wait';
    /** Index que l'optimiseur ignore sans le supprimer (`INVISIBLE` MySQL, `IGNORED` MariaDB). */
    case InvisibleIndexes = 'invisible_indexes';
    /** Plan d'exécution mesuré, la requête étant exécutée (`EXPLAIN ANALYZE`, `ANALYZE`). */
    case ActualPlan = 'actual_plan';
    /** Colonnes calculées (`GENERATED ALWAYS AS`, `ALIAS`). */
    case GeneratedColumns = 'generated_columns';
    /** Objets `CREATE SEQUENCE`. */
    case Sequences = 'sequences';
    /** Contrainte `CHECK` réellement appliquée aux écritures. */
    case EnforcedCheck = 'enforced_check';
}

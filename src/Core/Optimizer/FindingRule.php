<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

/**
 * Les règles de l'audit de schéma. Les valeurs sont stables : l'interface en tire ses messages.
 */
enum FindingRule: string
{
    case MissingPrimaryKey = 'missing_primary_key';
    case ForeignKeyWithoutIndex = 'foreign_key_without_index';
    case RedundantIndex = 'redundant_index';
    case ForeignKeyTypeMismatch = 'foreign_key_type_mismatch';

    // Audit d'exploitation (#80, suite) : lu dans les compteurs et le catalogue du moteur.
    case StaleStatistics = 'stale_statistics';
    case DeadTuples = 'dead_tuples';
    case ReclaimableSpace = 'reclaimable_space';
    case OrphanDefiner = 'orphan_definer';
}

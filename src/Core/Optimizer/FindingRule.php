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

    // Règles de conception, suite de #78 (jeux de caractères, collations, relations implicites, tables jumelles, types).
    case LegacyCharacterSet = 'legacy_character_set';
    case ImplicitForeignKey = 'implicit_foreign_key';
    case CollationMismatch = 'collation_mismatch';
    case TwinTable = 'twin_table';
    case CopyWithoutIndexes = 'copy_without_indexes';
    case TimestampDeadline = 'timestamp_deadline';
    case DateAsText = 'date_as_text';
    case NonTransactionalTable = 'non_transactional_table';

    case PartitionRangeExhausted = 'partition_range_exhausted';
    case TooManyPartitions = 'too_many_partitions';
    case TextPrimaryKey = 'text_primary_key';
    case FloatAmount = 'float_amount';
    case UnusedIndex = 'unused_index';
    case CounterNearLimit = 'counter_near_limit';
}

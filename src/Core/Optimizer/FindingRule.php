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

    // Règles de conception, suite de #78 (jeux de caractères, collations, relations implicites, tables jumelles, types).
    case LegacyCharacterSet = 'legacy_character_set';
    case ImplicitForeignKey = 'implicit_foreign_key';
    case CollationMismatch = 'collation_mismatch';
    case TwinTable = 'twin_table';
    case CopyWithoutIndexes = 'copy_without_indexes';
}

<?php

declare(strict_types=1);

namespace App\Core\Schema\Change\Algorithm;

/**
 * Les gestes de structure pour lesquels une règle d'algorithme existe (une règle par geste, vérifiée contre les vrais moteurs).
 */
enum AlterChange: string
{
    case AddColumn = 'add_column';
    case DropColumn = 'drop_column';
    case RenameColumn = 'rename_column';
    case RenameIndexedColumn = 'rename_indexed_column';
    case ChangeDefault = 'change_default';
    case DropNotNull = 'drop_not_null';
    case SetNotNull = 'set_not_null';
    case ChangeType = 'change_type';
    case AppendEnumMembers = 'append_enum_members';
    case WidenVarchar = 'widen_varchar';
    case AddIndex = 'add_index';
    case DropIndex = 'drop_index';
    case RenameIndex = 'rename_index';
    case AddForeignKey = 'add_foreign_key';
    case DropForeignKey = 'drop_foreign_key';
    case AddPrimaryKey = 'add_primary_key';
    case DropPrimaryKey = 'drop_primary_key';
    case RenameTable = 'rename_table';
    case AddColumnWithVolatileDefault = 'add_column_volatile_default';
}

<?php

declare(strict_types=1);

namespace App\Core\Schema;

use App\Core\Schema\Exception\InvalidSchemaException;

/**
 * Action d'une clé étrangère à la suppression ou à la mise à jour de la ligne référencée.
 */
enum ReferentialAction: string
{
    case NoAction = 'no_action';
    case Restrict = 'restrict';
    case Cascade = 'cascade';
    case SetNull = 'set_null';
    case SetDefault = 'set_default';

    public static function fromSql(string $sql): self
    {
        return match (strtoupper(trim($sql))) {
            'NO ACTION' => self::NoAction,
            'RESTRICT' => self::Restrict,
            'CASCADE' => self::Cascade,
            'SET NULL' => self::SetNull,
            'SET DEFAULT' => self::SetDefault,
            default => throw new InvalidSchemaException('Unknown referential action.'),
        };
    }
}

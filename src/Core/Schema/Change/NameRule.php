<?php

declare(strict_types=1);

namespace App\Core\Schema\Change;

use App\Core\Schema\Change\Exception\InvalidSchemaChangeException;

/**
 * Ce qu'on accepte comme nom de table, de colonne, d'index ou de contrainte : non vide, 128 caractères au plus, sans NUL ni
 * saut de ligne. Le reste (espaces, accents, guillemets) est permis : le dialecte met le nom entre guillemets et double ceux
 * qu'il contient.
 */
final class NameRule
{
    private const int MAX_LENGTH = 128;

    /**
     * @throws InvalidSchemaChangeException
     */
    public static function check(string $name, string $what): string
    {
        if ('' === trim($name)) {
            throw new InvalidSchemaChangeException(\sprintf('The %s needs a name.', $what));
        }
        if (mb_strlen($name) > self::MAX_LENGTH) {
            throw new InvalidSchemaChangeException(\sprintf('The %s name is too long.', $what));
        }
        if (1 === preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new InvalidSchemaChangeException(\sprintf('The %s name contains a control character.', $what));
        }

        return $name;
    }
}

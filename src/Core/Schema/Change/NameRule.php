<?php

declare(strict_types=1);

namespace App\Core\Schema\Change;

use App\Core\Schema\Change\Exception\InvalidSchemaChangeException;
use App\Core\Sql\SqlText;

/**
 * Ce qu'on accepte comme nom de table, de colonne, d'index ou de contrainte : non vide, 128 caractères au plus, sans contrôle (NUL compris), séparateur
 * de ligne (LF, CR, NEL, U+2028, U+2029) ni contrôle bidirectionnel, en UTF-8 valide. Le reste (espaces, accents, guillemets) est permis : le dialecte met le nom entre guillemets et double ceux
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
        if (!SqlText::isSafeName($name)) {
            throw new InvalidSchemaChangeException(\sprintf('The %s name contains a control character, a line separator or invalid text.', $what));
        }

        return $name;
    }
}

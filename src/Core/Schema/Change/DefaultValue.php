<?php

declare(strict_types=1);

namespace App\Core\Schema\Change;

use App\Core\Schema\Change\Exception\InvalidSchemaChangeException;

/**
 * La valeur par défaut d'une colonne, saisie dans un seul champ de texte. Jamais du SQL brut : le texte est lu comme NULL,
 * un nombre, une chaîne (entre apostrophes ou non), ou l'une des quelques expressions connues (date du jour, UUID, vrai/faux,
 * séquence). Tout le reste devient une CHAÎNE, que le dialecte échappe : « lower(name) » donne le texte lower(name), pas
 * un appel. C'est le prix de ne jamais laisser passer d'expression arbitraire.
 */
final readonly class DefaultValue
{
    private const int MAX_LENGTH = 500;

    private const string EXPRESSION = '/^(?:now\(\)|now64\(\d?\)|today\(\)|generateuuidv4\(\)|uuid\(\)|gen_random_uuid\(\)|uuid_generate_v4\(\)|current_(?:timestamp|time)(?:\(\d\))?|current_date|localtimestamp(?:\(\d\))?|true|false|nextval\(\'[A-Za-z0-9_.]+\'::regclass\))$/i';

    private function __construct(
        public DefaultKind $kind,
        public string $value,
    ) {
    }

    /**
     * @throws InvalidSchemaChangeException
     */
    public static function parse(string $text): ?self
    {
        if (str_contains($text, "\0")) {
            throw new InvalidSchemaChangeException('The default value contains a character that is not allowed.');
        }
        $text = trim($text);
        if ('' === $text) {
            return null;
        }
        if (\strlen($text) > self::MAX_LENGTH) {
            throw new InvalidSchemaChangeException('The default value is too long.');
        }
        if ('null' === strtolower($text)) {
            return new self(DefaultKind::Null, 'null');
        }
        if (1 === preg_match('/^-?\d+(?:\.\d+)?$/', $text)) {
            return new self(DefaultKind::Number, $text);
        }
        if (1 === preg_match(self::EXPRESSION, $text)) {
            return new self(DefaultKind::Expression, 'nextval(' === strtolower(substr($text, 0, 8)) ? $text : strtolower($text));
        }
        $quoted = self::unquoted($text);

        return new self(DefaultKind::Literal, $quoted ?? $text);
    }

    /**
     * « 'it''s' » ou « 'x'::character varying » (la forme que PostgreSQL rapporte) : le contenu, sans apostrophes ni cast.
     */
    private static function unquoted(string $text): ?string
    {
        if (1 !== preg_match("/^'((?:[^']|'')*)'(?:::[A-Za-z_][A-Za-z0-9_ ]*(?:\\(\\d+(?:, ?\\d+)?\\))?)?$/", $text, $match)) {
            return null;
        }

        return str_replace("''", "'", $match[1]);
    }
}

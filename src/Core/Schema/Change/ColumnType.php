<?php

declare(strict_types=1);

namespace App\Core\Schema\Change;

use App\Core\Schema\Change\Exception\InvalidSchemaChangeException;

/**
 * Un type de colonne saisi par l'utilisateur, vérifié avant de finir dans du SQL. Les moteurs écrivent leurs types librement
 * (« character varying(20) », « Enum8('a' = 1) », « LowCardinality(Nullable(String)) ») : on ne les énumère donc pas, on
 * n'accepte que leur FORME, sans rien qui puisse sortir de la définition d'une colonne. Mots, nombres, chaînes entre
 * apostrophes fermées (apostrophe doublée pour échapper, jamais d'antislash), virgules, signe égal, parenthèses équilibrées,
 * `[]` ; les mots qui poseraient une contrainte (PRIMARY, DEFAULT, REFERENCES…) sont refusés : c'est aux champs dédiés de le faire.
 */
final class ColumnType
{
    private const int MAX_LENGTH = 200;

    /** Mots qui ne décrivent pas un type mais ajouteraient une contrainte ou une clause à la définition de la colonne. */
    private const array FORBIDDEN_WORDS = [
        'primary', 'key', 'references', 'default', 'not', 'null', 'generated', 'constraint', 'check', 'unique', 'auto_increment',
        'autoincrement', 'identity', 'comment', 'collate', 'alter', 'drop', 'add', 'select', 'insert', 'update', 'delete', 'as',
        'materialized', 'alias', 'ephemeral', 'codec', 'ttl',
    ];

    /**
     * @throws InvalidSchemaChangeException
     */
    public static function validated(string $type): string
    {
        // Avant trim() : il retire aussi les NUL de bout.
        if (str_contains($type, "\0")) {
            throw new InvalidSchemaChangeException('The type contains a character that is not allowed.');
        }
        $type = trim($type);
        if ('' === $type) {
            throw new InvalidSchemaChangeException('A column needs a type.');
        }
        if (\strlen($type) > self::MAX_LENGTH) {
            throw new InvalidSchemaChangeException('The type is too long.');
        }

        $out = '';
        $depth = 0;
        $length = \strlen($type);
        $first = true;
        for ($i = 0; $i < $length; ++$i) {
            $char = $type[$i];

            if (ctype_space($char)) {
                if ("\n" === $char || "\r" === $char || "\t" === $char) {
                    throw new InvalidSchemaChangeException('The type cannot contain line breaks.');
                }
                if ('' !== $out && !str_ends_with($out, ' ') && !str_ends_with($out, '(')) {
                    $out .= ' ';
                }
                continue;
            }

            if (ctype_alpha($char) || '_' === $char) {
                $start = $i;
                while ($i + 1 < $length && (ctype_alnum($type[$i + 1]) || '_' === $type[$i + 1] || '.' === $type[$i + 1])) {
                    ++$i;
                }
                $word = substr($type, $start, $i - $start + 1);
                foreach (explode('.', $word) as $part) {
                    if (\in_array(strtolower($part), self::FORBIDDEN_WORDS, true)) {
                        throw new InvalidSchemaChangeException('The type contains a word that is not part of a type.');
                    }
                }
                $out .= $word;
                $first = false;
                continue;
            }

            if ($first) {
                throw new InvalidSchemaChangeException('A type starts with its name.');
            }

            if (ctype_digit($char) || ('-' === $char && $depth > 0)) {
                if ($depth < 1 && ctype_digit($char) && !str_ends_with($out, '[')) {
                    throw new InvalidSchemaChangeException('A number is only valid inside parentheses.');
                }
                $out .= $char;
                continue;
            }

            switch ($char) {
                case '(':
                    ++$depth;
                    $out .= '(';
                    break;
                case ')':
                    if (--$depth < 0) {
                        throw new InvalidSchemaChangeException('The parentheses of the type are not balanced.');
                    }
                    $out = rtrim($out, ' ').')';
                    break;
                case ',':
                    if ($depth < 1) {
                        throw new InvalidSchemaChangeException('A comma is only valid inside parentheses.');
                    }
                    $out = rtrim($out, ' ').', ';
                    break;
                case '=':
                    if ($depth < 1) {
                        throw new InvalidSchemaChangeException('An equal sign is only valid inside parentheses.');
                    }
                    $out = rtrim($out, ' ').' = ';
                    break;
                case '[':
                case ']':
                    $out .= $char;
                    break;
                case "'":
                    if ($depth < 1) {
                        throw new InvalidSchemaChangeException('A quoted value is only valid inside parentheses.');
                    }
                    $literal = "'";
                    $closed = false;
                    while (++$i < $length) {
                        $next = $type[$i];
                        if ('\\' === $next || "\n" === $next || "\r" === $next || "\0" === $next) {
                            throw new InvalidSchemaChangeException('A quoted value cannot contain a backslash or a line break.');
                        }
                        $literal .= $next;
                        if ("'" === $next) {
                            if ($i + 1 < $length && "'" === $type[$i + 1]) {
                                $literal .= "'";
                                ++$i;
                                continue;
                            }
                            $closed = true;
                            break;
                        }
                    }
                    if (!$closed) {
                        throw new InvalidSchemaChangeException('A quoted value is not closed.');
                    }
                    $out .= $literal;
                    break;
                default:
                    throw new InvalidSchemaChangeException('The type contains a character that is not allowed.');
            }
        }

        if ($depth > 0) {
            throw new InvalidSchemaChangeException('The parentheses of the type are not balanced.');
        }
        if (1 === preg_match('/\[\d*\]\S/', $out) || substr_count($out, '[') !== substr_count($out, ']')) {
            throw new InvalidSchemaChangeException('The brackets of the type are not balanced.');
        }

        return rtrim($out);
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Schema\Change\Algorithm;

/**
 * Lit la FORME de deux types et dit à quel geste de la table de règles le changement correspond (nul : on ne sait pas, donc on
 * n'affirme rien). Seuls les motifs vérifiés contre les vrais moteurs sont reconnus ; tout type qui porte un jeu de caractères, une
 * collation, un zerofill ou qu'on ne sait pas lire est laissé « inconnu ».
 */
final class TypeChangeClassifier
{
    private const array MYSQL_BASES = [
        'tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double', 'char', 'varchar', 'binary', 'varbinary',
        'tinytext', 'text', 'mediumtext', 'longtext', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'date', 'time', 'datetime', 'timestamp', 'year',
    ];

    private const array POSTGRES_INTS = ['smallint', 'int', 'bigint'];

    private const array ALIASES = [
        'integer' => 'int', 'int4' => 'int', 'int8' => 'bigint', 'int2' => 'smallint', 'dec' => 'decimal', 'numeric' => 'decimal', 'fixed' => 'decimal',
        'character varying' => 'varchar', 'character' => 'char',
    ];

    public static function forMySqlFamily(string $old, string $new): ?AlterChange
    {
        $o = self::shape($old);
        $n = self::shape($new);
        if (null === $o || null === $n) {
            return null;
        }
        if ('enum' === $o['base'] && 'enum' === $n['base']) {
            return self::enumChange($o['args'], $n['args']);
        }
        if (!\in_array($o['base'], self::MYSQL_BASES, true) || !\in_array($n['base'], self::MYSQL_BASES, true)) {
            return null;
        }
        if ($o['base'] !== $n['base'] || $o['unsigned'] !== $n['unsigned']) {
            return AlterChange::ChangeType;
        }
        if ('decimal' === $o['base'] && str_replace(' ', '', (string) $o['args']) !== str_replace(' ', '', (string) $n['args'])) {
            return AlterChange::ChangeType;
        }
        if ('varchar' === $o['base'] && null !== $o['args'] && null !== $n['args'] && ctype_digit($o['args']) && ctype_digit($n['args']) && (int) $n['args'] < (int) $o['args']) {
            return AlterChange::ChangeType;
        }

        return null;
    }

    public static function forPostgreSql(string $old, string $new): ?AlterChange
    {
        $o = self::shape($old);
        $n = self::shape($new);
        if (null === $o || null === $n || $o['unsigned'] || $n['unsigned']) {
            return null;
        }
        $oldInt = \in_array($o['base'], self::POSTGRES_INTS, true);
        $newInt = \in_array($n['base'], self::POSTGRES_INTS, true);
        $newText = \in_array($n['base'], ['varchar', 'text'], true);
        if ($oldInt && $newInt && $o['base'] !== $n['base']) {
            return AlterChange::ChangeType;
        }
        if ($oldInt && $newText) {
            return AlterChange::ChangeType;
        }
        if ('varchar' === $o['base'] && 'text' === $n['base']) {
            return AlterChange::WidenVarchar;
        }
        if ('varchar' === $o['base'] && 'varchar' === $n['base'] && null !== $o['args'] && null !== $n['args'] && ctype_digit($o['args']) && ctype_digit($n['args']) && (int) $n['args'] > (int) $o['args']) {
            return AlterChange::WidenVarchar;
        }

        return null;
    }

    /**
     * @return array{base: string, args: ?string, unsigned: bool}|null
     */
    private static function shape(string $type): ?array
    {
        $text = trim($type);
        $unsigned = 1 === preg_match('/\sunsigned$/i', $text);
        if ($unsigned) {
            $text = trim(substr($text, 0, -8));
        }
        if (1 === preg_match('/zerofill|signed|charset|character set|collate|\bbinary$|\bascii\b|\bunicode\b|\[|\bwith\b|\bwithout\b/i', $text)) {
            return null;
        }
        if (1 !== preg_match('/^([a-z][a-z0-9_]*(?: varying)?)\s*(?:\((.*)\))?$/i', $text, $m)) {
            return null;
        }
        $base = strtolower($m[1]);
        $base = self::ALIASES[$base] ?? $base;

        return ['base' => $base, 'args' => isset($m[2]) && '' !== $m[2] ? $m[2] : null, 'unsigned' => $unsigned];
    }

    /**
     * Ajouter des membres à la FIN d'une énumération ne touche pas aux données (tant que l'octet de stockage ne change pas : 255
     * membres au plus) ; tout autre changement de la liste recopie la table.
     */
    private static function enumChange(?string $old, ?string $new): ?AlterChange
    {
        $before = self::members($old);
        $after = self::members($new);
        if (null === $before || null === $after) {
            return null;
        }
        if (\count($after) > \count($before) && \array_slice($after, 0, \count($before)) === $before) {
            return \count($after) <= 255 ? AlterChange::AppendEnumMembers : null;
        }

        return AlterChange::ChangeType;
    }

    /**
     * @return list<string>|null
     */
    private static function members(?string $args): ?array
    {
        if (null === $args || '' === $args) {
            return null;
        }
        $length = \strlen($args);
        $members = [];
        $i = 0;
        while ($i < $length) {
            if ("'" !== $args[$i]) {
                return null;
            }
            $value = '';
            for (++$i; $i < $length; ++$i) {
                if ("'" === $args[$i]) {
                    if ("'" === ($args[$i + 1] ?? '')) {
                        $value .= "'";
                        ++$i;
                        continue;
                    }
                    break;
                }
                $value .= $args[$i];
            }
            if ($i >= $length) {
                return null;
            }
            $members[] = $value;
            ++$i;
            if ($i < $length) {
                if (',' !== $args[$i]) {
                    return null;
                }
                ++$i;
            }
        }

        return $members;
    }
}

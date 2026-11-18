<?php

declare(strict_types=1);

namespace App\Core\Schema\Change;

/**
 * Les valeurs d'un type ENUM ou SET de MySQL / MariaDB, lues dans son texte (« enum('a','it''s') »). Nul si ce n'est pas l'un de ces
 * types ou si la liste est mal fermée : on ne devine pas.
 */
final class EnumMembers
{
    /**
     * @return list<string>|null
     */
    public static function of(string $type): ?array
    {
        if (1 !== preg_match('/^(?:enum|set)\s*\((.*)\)$/is', trim($type), $match)) {
            return null;
        }
        $list = $match[1];
        $values = [];
        $length = \strlen($list);
        $i = 0;
        while ($i < $length) {
            if ("'" !== $list[$i]) {
                return null;
            }
            $value = '';
            for (++$i; $i < $length; ++$i) {
                if ("'" === $list[$i]) {
                    if ("'" === ($list[$i + 1] ?? '')) {
                        $value .= "'";
                        ++$i;
                        continue;
                    }
                    break;
                }
                $value .= $list[$i];
            }
            if ($i >= $length) {
                return null;
            }
            $values[] = $value;
            ++$i;
            while ($i < $length && ctype_space($list[$i])) {
                ++$i;
            }
            if ($i < $length) {
                if (',' !== $list[$i]) {
                    return null;
                }
                ++$i;
                while ($i < $length && ctype_space($list[$i])) {
                    ++$i;
                }
                if ($i >= $length) {
                    return null;
                }
            }
        }

        return [] === $values ? null : $values;
    }
}

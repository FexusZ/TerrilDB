<?php

declare(strict_types=1);

namespace App\Core\Query;

/**
 * Une valeur de cellule prête à être sérialisée : les chaînes binaires (qui feraient échouer un encodage JSON)
 * deviennent de l'hexadécimal, coupé à 256 octets ; les entiers trop grands pour JavaScript deviennent des chaînes.
 */
final class CellValue
{
    private const BINARY_PREVIEW_BYTES = 256;
    private const MAX_SAFE_INTEGER = 9_007_199_254_740_991;

    public static function normalize(mixed $value): bool|float|int|string|null
    {
        if (\is_int($value)) {
            // Au-delà de 2^53 - 1, un nombre JSON perd sa précision dans le navigateur.
            return abs($value) > self::MAX_SAFE_INTEGER ? (string) $value : $value;
        }
        if (null === $value || \is_bool($value) || \is_float($value)) {
            return $value;
        }
        if (\is_resource($value)) {
            $value = stream_get_contents($value);
            if (false === $value) {
                return null;
            }

            return self::hex($value);
        }
        if (\is_string($value)) {
            return mb_check_encoding($value, 'UTF-8') ? $value : self::hex($value);
        }

        return \sprintf('[unsupported: %s]', get_debug_type($value));
    }

    private static function hex(string $binary): string
    {
        $preview = bin2hex(substr($binary, 0, self::BINARY_PREVIEW_BYTES));

        return '0x'.$preview.(\strlen($binary) > self::BINARY_PREVIEW_BYTES ? '…' : '');
    }
}

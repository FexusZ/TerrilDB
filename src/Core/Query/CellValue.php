<?php

declare(strict_types=1);

namespace App\Core\Query;

/**
 * Une valeur de cellule prête à être sérialisée : les chaînes binaires (qui feraient échouer un encodage JSON)
 * deviennent de l'hexadécimal, coupé à 256 octets.
 */
final class CellValue
{
    private const BINARY_PREVIEW_BYTES = 256;

    public static function normalize(mixed $value): bool|float|int|string|null
    {
        if (null === $value || \is_bool($value) || \is_int($value) || \is_float($value)) {
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

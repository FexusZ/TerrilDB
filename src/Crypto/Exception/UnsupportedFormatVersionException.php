<?php

declare(strict_types=1);

namespace App\Crypto\Exception;

final class UnsupportedFormatVersionException extends CryptoException
{
    public static function for(string $what, int $version): self
    {
        return new self(\sprintf('Unsupported %s format version %d.', $what, $version));
    }
}

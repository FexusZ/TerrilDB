<?php

declare(strict_types=1);

namespace App\Core\Connection;

use App\Core\Connection\Exception\InvalidConnectionParametersException;

/**
 * Contrôles communs aux transports (hôte, port, noms).
 *
 * @internal
 */
final class TransportRules
{
    public static function assertHost(string $host, string $label): void
    {
        if ('' === $host || \strlen($host) > Transport::HOST_MAX_LENGTH || 1 !== preg_match('/^[^\s\p{Cc}]+$/uD', $host)) {
            throw new InvalidConnectionParametersException(\sprintf('The %s host is not valid.', $label));
        }
    }

    public static function assertPort(int $port, string $label): void
    {
        if ($port < 1 || $port > 65535) {
            throw new InvalidConnectionParametersException(\sprintf('The %s port must be between 1 and 65535.', $label));
        }
    }

    public static function assertName(string $value, string $label, int $maxBytes = 128): void
    {
        if ('' === $value || \strlen($value) > $maxBytes || 1 === preg_match('/\p{Cc}/u', $value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidConnectionParametersException(\sprintf('The %s is not valid.', $label));
        }
    }
}

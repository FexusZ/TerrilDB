<?php

declare(strict_types=1);

namespace App\Connection;

/**
 * Résolution par le système (comme le fera le driver au moment de se connecter) : les écritures détournées d'une
 * adresse (« 2130706433 » pour 127.0.0.1) tombent donc sur la même adresse que celle qui sera réellement jointe.
 */
final class SystemHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $host = trim($host, "[] \t");
        if ('' === $host) {
            return [];
        }
        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $addresses = [];
        $v4 = @gethostbynamel($host);
        if (false !== $v4) {
            $addresses = $v4;
        }
        $v6 = @dns_get_record($host, \DNS_AAAA);
        if (\is_array($v6)) {
            foreach ($v6 as $record) {
                if (isset($record['ipv6']) && \is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Connection;

use App\Connection\HostResolver;

/**
 * Un résolveur de test qui compte les questions posées, pour prouver qu'un nom n'est résolu qu'une fois.
 */
final class CountingResolver implements HostResolver
{
    /** @var array<string, int> */
    public array $calls = [];

    /**
     * @param array<string, list<string>> $names
     */
    public function __construct(public array $names = [])
    {
    }

    public function resolve(string $host): array
    {
        $this->calls[$host] = ($this->calls[$host] ?? 0) + 1;

        return false !== filter_var($host, \FILTER_VALIDATE_IP) ? [$host] : ($this->names[$host] ?? []);
    }
}

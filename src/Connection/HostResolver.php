<?php

declare(strict_types=1);

namespace App\Connection;

/**
 * Les adresses IP (v4 et v6) qu'un nom d'hôte désigne. Une adresse littérale se désigne elle-même ; un nom introuvable
 * ne rend rien.
 */
interface HostResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array;
}

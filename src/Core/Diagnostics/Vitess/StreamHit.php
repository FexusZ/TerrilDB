<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Vitess;

/**
 * Un flux VReplication dont une table touchée est la source : le workflow (vide si le serveur ne le dit pas), la table source et les tables
 * qu'il remplit (vide quand le flux se définit par une expression, qui ne nomme rien). Un nom de la base cible : donnée, jamais du SQL.
 */
final readonly class StreamHit
{
    /**
     * @param list<string> $targets
     */
    public function __construct(public string $workflow, public string $table, public array $targets)
    {
    }
}

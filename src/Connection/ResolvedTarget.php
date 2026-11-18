<?php

declare(strict_types=1);

namespace App\Connection;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;

/**
 * Une cible prête à être jointe PAR UN AUTRE PROCESSUS (le worker des scripts détachés) : le moteur et les paramètres en clair, adresse
 * déjà contrôlée et épinglée. Rien n'est ouvert. À ne jamais garder : les paramètres portent le mot de passe de la cible.
 */
final readonly class ResolvedTarget
{
    public function __construct(
        public Engine $engine,
        #[\SensitiveParameter] public ConnectionParameters $parameters,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['engine' => $this->engine->value, 'parameters' => '***'];
    }
}

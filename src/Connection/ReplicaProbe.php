<?php

declare(strict_types=1);

namespace App\Connection;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Diagnostics\Vitess\ReplicaAvailability;
use App\Entity\User;

/**
 * Cherche un réplica à lire pour des paramètres de connexion (voir `VitessReplicaCheck`) ; une interface pour juger le rappel
 * sans serveur Vitess.
 */
interface ReplicaProbe
{
    public function inspect(User $user, Engine $engine, ConnectionParameters $parameters): ReplicaAvailability;
}

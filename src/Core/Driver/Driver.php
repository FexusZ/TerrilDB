<?php

declare(strict_types=1);

namespace App\Core\Driver;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Tout ce qui est propre à un moteur passe par ici : un driver sait ouvrir une connexion vers les moteurs
 * qu'il sert et fournir leur introspecteur. Un nouveau moteur s'ajoute en écrivant un driver.
 */
#[AutoconfigureTag('app.driver')]
interface Driver
{
    /**
     * @return list<Engine>
     */
    public function engines(): array;

    /**
     * @throws TargetConnectionFailedException
     */
    public function connect(Engine $engine, ConnectionParameters $parameters): TargetConnection;
}

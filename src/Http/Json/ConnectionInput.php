<?php

declare(strict_types=1);

namespace App\Http\Json;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;

/**
 * Lit une définition de connexion dans un corps JSON, pour l'interface web comme pour l'API à jetons.
 */
final class ConnectionInput
{
    /**
     * @param ConnectionParameters|null $current les paramètres déjà enregistrés : un mot de passe absent les conserve
     *
     * @return array{string, Engine, ConnectionParameters}
     */
    public static function read(JsonInput $input, ?ConnectionParameters $current, bool $nameRequired = true): array
    {
        $engine = $input->engine('engine');
        $password = $input->optionalString('password') ?? $current?->password() ?? '';

        return [
            $nameRequired ? $input->string('name') : ($input->optionalString('name') ?? ''),
            $engine,
            new ConnectionParameters(
                $input->string('host'),
                $input->optionalInt('port') ?? $engine->defaultPort(),
                $input->optionalString('database') ?? '',
                $input->string('username'),
                $password,
            ),
        ];
    }
}

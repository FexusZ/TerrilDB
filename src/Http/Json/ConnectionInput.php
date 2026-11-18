<?php

declare(strict_types=1);

namespace App\Http\Json;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Connection\Exception\InvalidConnectionParametersException;
use App\Core\Connection\SshJump;
use App\Entity\ConnectionColor;
use App\Entity\Exception\InvalidDatabaseConnectionException;

/**
 * Lit une définition de connexion dans un corps JSON, pour l'interface web comme pour l'API à jetons.
 */
final class ConnectionInput
{
    /**
     * La couleur de repère (facultative : absente, nulle ou vide = aucune).
     */
    public static function color(JsonInput $input): ?ConnectionColor
    {
        $value = $input->optionalString('color');

        return null === $value || '' === $value ? null : (ConnectionColor::tryFrom($value) ?? throw new InvalidDatabaseConnectionException('The color is not valid.'));
    }

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
                transport: self::transport($input, $current),
            ),
        ];
    }

    /**
     * Le rebond SSH. Champ absent : la connexion garde celui qu'elle a (une modification ne retire jamais un rebond en silence,
     * et n'en ajoute pas) ; `null` : on le retire. La clé privée absente garde celle qui est enregistrée (comme le mot de passe) ;
     * l'empreinte absente garde celle du MÊME bastion (hôte et port), jamais celle d'un autre.
     */
    private static function transport(JsonInput $input, ?ConnectionParameters $current): ?SshJump
    {
        $currentJump = $current?->transport instanceof SshJump ? $current->transport : null;
        if (!$input->hasKey('transport')) {
            return $currentJump;
        }
        if (!$input->has('transport')) {
            return null;
        }

        $jump = $input->object('transport');
        if ('ssh' !== $jump->string('type')) {
            throw new InvalidConnectionParametersException('The connection transport is not supported.');
        }
        $host = $jump->string('host');
        $port = $jump->optionalInt('port') ?? 22;
        $key = $jump->optionalString('privateKey') ?? $currentJump?->privateKey() ?? throw new InvalidConnectionParametersException('The jump key is required.');
        $sameBastion = null !== $currentJump && 0 === strcasecmp($currentJump->host, $host) && $currentJump->port === $port;

        return new SshJump(
            $host,
            $port,
            $jump->string('username'),
            $key,
            $jump->optionalString('hostKeyFingerprint') ?? ($sameBastion ? $currentJump->hostKeyFingerprint : null),
        );
    }
}

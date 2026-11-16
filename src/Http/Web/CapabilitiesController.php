<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Core\Driver\EngineCapability;
use App\Core\Driver\ServerCapabilities;
use App\Core\Driver\TargetConnection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Ce que le serveur d'une connexion sait faire, d'après le moteur et la version lue à l'ouverture (`CapabilityMatrix`) :
 * la source unique pour griser une aide de l'interface. Rien n'est exécuté sur la cible.
 */
final readonly class CapabilitiesController
{
    private const ID = ['id' => '[0-9a-fA-F-]{36}'];

    public function __construct(private TargetAccess $targets)
    {
    }

    #[Route('/web/connections/{id}/capabilities', requirements: self::ID, methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $capabilities = $this->targets->withSaved(Uuid::fromString($id), static fn (TargetConnection $target): ServerCapabilities => $target->capabilities());
        $version = $capabilities->version;

        return new JsonResponse([
            // La famille reconnue : un serveur enregistré comme MySQL peut annoncer MariaDB.
            'engine' => $capabilities->family->value,
            'version' => null === $version ? null : ['major' => $version->major, 'minor' => $version->minor, 'patch' => $version->patch, 'flavour' => $version->flavour->value],
            'capabilities' => array_combine(
                array_map(static fn (EngineCapability $c): string => $c->value, EngineCapability::cases()),
                array_map($capabilities->supports(...), EngineCapability::cases()),
            ),
        ]);
    }
}

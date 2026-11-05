<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Driver\DriverRegistry;
use App\Core\Driver\TargetConnection;
use App\Http\Json\TargetSession;
use Symfony\Component\Uid\Uuid;

/**
 * Ouvre une base cible pour l'utilisateur de la session web (voir TargetSession) ou en essaie les paramètres.
 */
final readonly class TargetAccess
{
    public function __construct(
        private TargetSession $targets,
        private DriverRegistry $drivers,
        private WebSession $session,
    ) {
    }

    /**
     * @template T
     *
     * @param \Closure(TargetConnection): T $action
     *
     * @return T
     */
    public function withSaved(Uuid $id, \Closure $action, ?string $database = null): mixed
    {
        return $this->targets->with($this->session->user(), $this->session->key(), $id, $action, $database);
    }

    /**
     * Essaie des paramètres sans rien enregistrer. Rend la durée de connexion en millisecondes.
     */
    public function probe(Engine $engine, ConnectionParameters $parameters): int
    {
        $started = hrtime(true);
        $this->drivers->for($engine)->connect($engine, $parameters)->close();

        return intdiv(hrtime(true) - $started, 1_000_000);
    }
}

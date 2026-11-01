<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Connection\TargetOpener;
use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Driver\DriverRegistry;
use App\Core\Driver\TargetConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Ouvre une base cible le temps d'une action, puis la ferme quoi qu'il arrive : les identifiants ne vivent pas
 * plus longtemps que la requête.
 */
final readonly class TargetAccess
{
    public function __construct(
        private TargetOpener $opener,
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
    public function withSaved(Uuid $id, \Closure $action): mixed
    {
        $target = $this->opener->open($this->session->user(), $this->session->key(), $id);

        try {
            return $action($target);
        } finally {
            $target->close();
        }
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

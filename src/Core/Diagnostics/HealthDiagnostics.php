<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

use App\Core\Diagnostics\Exception\DiagnosticsFailedException;
use App\Core\Diagnostics\Health\HealthIndicator;
use App\Core\Diagnostics\Health\HealthSample;
use App\Core\Diagnostics\Health\HealthWindow;

/**
 * Santé d'un serveur par relevés de compteurs. Un compteur cumulé depuis le démarrage ne prouve rien seul : le moteur ne
 * donne qu'UN relevé (`sample`), l'écart entre deux relevés se calcule dans le cœur (`HealthSampler`), jamais ici ni dans un
 * contrôleur. Requêtes de catalogue fixes, en lecture seule.
 */
interface HealthDiagnostics
{
    public function status(): DiagnosticStatus;

    /**
     * @throws DiagnosticsFailedException
     */
    public function sample(): HealthSample;

    /**
     * Les indicateurs de ce moteur pour deux relevés (calcul pur, sans accès au serveur).
     *
     * @return list<HealthIndicator>
     */
    public function indicators(HealthWindow $window): array;
}

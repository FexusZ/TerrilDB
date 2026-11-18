<?php

declare(strict_types=1);

namespace App\Core\Query\Script;

/**
 * Ce qu'un exécuteur de script dit et demande pendant qu'il tourne (exécution détachée, #169). Il ne contient jamais de
 * logique du moteur : l'exécuteur l'appelle, le worker l'implémente.
 */
interface ScriptProgress
{
    /**
     * Demandé AVANT chaque instruction : vrai = s'arrêter proprement ici. Ce qui a été exécuté est annulé autant que le moteur
     * le permet (comme après une erreur) et la cause de l'arrêt est `cancelled`. Jamais d'interruption d'une instruction en cours.
     */
    public function shouldStop(): bool;

    /**
     * Une instruction vient de s'exécuter (appliquée) ou d'échouer. Tant que la transaction n'est pas validée, « appliquée » est
     * provisoire : seul le compte rendu final dit si elle est restée ou a été annulée.
     */
    public function executed(ScriptStepResult $step): void;
}

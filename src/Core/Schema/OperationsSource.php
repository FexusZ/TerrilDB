<?php

declare(strict_types=1);

namespace App\Core\Schema;

/**
 * Ce qu'un introspecteur sait de l'exploitation d'une base (audit d'exploitation) : lecture seule, jamais une exception pour une
 * source illisible (le rapport dit pourquoi). Les moteurs qui n'ont rien de tel n'implémentent pas l'interface.
 */
interface OperationsSource
{
    /**
     * Les mesures d'entretien des tables de la base ou du schéma ouvert (statistiques, tuples morts, place libre).
     */
    public function upkeep(): UpkeepReport;

    /**
     * Les vues, routines, déclencheurs et événements dont le créateur n'est pas un compte du serveur.
     */
    public function orphanDefiners(): DefinerReport;
}

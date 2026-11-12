<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * Les sessions en cours sur le serveur cible. Requêtes de catalogue fixes, en lecture seule, écrites par le serveur :
 * jamais le texte d'un client, jamais la console. Un manque de droit ou une source éteinte se dit dans l'état, pas par une erreur.
 */
interface SessionDiagnostics
{
    /**
     * L'état de la source, sans lire les sessions.
     */
    public function status(): DiagnosticStatus;

    public function sessions(): SessionReport;
}

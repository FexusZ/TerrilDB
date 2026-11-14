<?php

declare(strict_types=1);

namespace App\Core\Query\Script;

/**
 * Où en est la cible après le script.
 */
enum ScriptStatus: string
{
    /** Tout est passé et validé. */
    case Committed = 'committed';
    /** Jeu à blanc réussi : tout a été exécuté puis annulé. */
    case DryRun = 'dry_run';
    /** Une instruction a échoué et la transaction a tout annulé (hors ce que le moteur avait déjà validé seul). */
    case RolledBack = 'rolled_back';
    /** Une instruction a échoué, sans transaction qui annule : celles d'avant restent appliquées. */
    case Partial = 'partial';
    /** Une instruction a échoué et rien n'est resté appliqué. */
    case Failed = 'failed';
}

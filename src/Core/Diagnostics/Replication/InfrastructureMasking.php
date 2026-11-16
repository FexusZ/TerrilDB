<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Replication;

/**
 * Réglage d'installation (`TERRILDB_MASK_INFRASTRUCTURE`, vrai par défaut) : le texte d'erreur de réplication masque ou non le compte,
 * l'hôte et l'adresse de la source. Un mot de passe, un jeton ou une adresse avec identifiants restent toujours masqués ; une donnée
 * de table (valeur de ligne) n'est jamais masquée.
 */
final readonly class InfrastructureMasking
{
    public function __construct(
        public bool $enabled = true,
    ) {
    }
}

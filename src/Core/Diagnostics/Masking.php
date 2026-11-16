<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * Réglage d'installation (`TERRILDB_MASKING`, vrai par défaut). Le masquage est une SURCOUCHE de prudence de TerrilDB, pas une
 * exigence : activé, un texte montré par les diagnostics cache ses secrets (mot de passe, jeton, adresse avec identifiants) et
 * l'infrastructure (compte, hôte, adresse) ; coupé (`0`), il est rendu tel que le moteur le dit, comme le ferait un client SQL.
 * Les valeurs de lignes d'une table, elles, ne sont jamais masquées dans un cas comme dans l'autre : les droits se règlent côté base cible.
 */
final readonly class Masking
{
    public function __construct(
        public bool $enabled = true,
    ) {
    }
}

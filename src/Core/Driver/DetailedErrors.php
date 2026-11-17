<?php

declare(strict_types=1);

namespace App\Core\Driver;

/**
 * Réglage d'installation (`TERRILDB_DETAILED_ERRORS`, FAUX par défaut). Faux : TerrilDB garde son contrat d'origine, un échec de
 * connexion reste « cible injoignable » et une instruction refusée garde le code générique, sans rien dire de plus sur le réseau visé.
 * Vrai : les codes précis (`auth_failed`, `auth_requires_tls`, `too_many_connections`, `engine_permission_denied`, `duplicate_key`) et
 * leurs conseils sont rendus, à tous les membres de l'installation : un serveur qui répond par un refus d'identifiants prouve qu'il est
 * là, ce qui cartographie les machines joignables depuis TerrilDB. Le choix revient à qui installe. Dans les deux cas le message
 * brut du moteur d'une connexion n'est jamais montré.
 */
final readonly class DetailedErrors
{
    public function __construct(
        public bool $enabled = false,
    ) {
    }
}

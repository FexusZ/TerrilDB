<?php

declare(strict_types=1);

namespace App\Core\Schema\Change;

/**
 * Ce qu'une modification fait perdre au-delà du texte du SQL, pour que la confirmation le liste : `system_history` (table versionnée
 * supprimée : son historique part avec elle, `subject` = la table), `values_removed` (valeurs retirées d'une liste ENUM / SET :
 * `subject` = la colonne, `values` = les valeurs ; les lignes qui les portent bloquent le changement ou les perdent). Codes stables,
 * repris par l'interface.
 */
final readonly class PlanLoss
{
    /**
     * @param list<string> $values
     */
    public function __construct(public string $code, public string $subject, public array $values = [])
    {
    }
}

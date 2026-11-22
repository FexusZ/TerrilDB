<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Vitess;

/**
 * Une ligne de `SHOW VITESS_TABLETS` qu'on ne propose pas comme cible, et pourquoi : mieux vaut dire « 2 tablets ne sont pas
 * ciblables » que de les faire disparaître (on croirait un tablet perdu). Rien du serveur n'est recopié : ni alias, ni hôte, ni
 * valeur inconnue ; le type n'est rendu que s'il est dans la liste fermée des types de Vitess.
 */
final readonly class SkippedTablet
{
    /** Un type que vtgate ne sait pas viser (sauvegarde en cours, restauration, tablet vidé, de rechange…). */
    public const TYPE_NOT_TARGETABLE = 'type_not_targetable';
    /** Alias, keyspace, shard ou cellule d'une forme inattendue : on ne s'en sert pas pour construire un nom de cible. */
    public const UNEXPECTED_NAME = 'unexpected_name';
    /** La ligne n'a pas les colonnes attendues. */
    public const UNREADABLE = 'unreadable';

    public function __construct(
        public string $reason,
        /** Le type du tablet s'il fait partie de ceux que Vitess connaît, sinon null. */
        public ?string $type,
    ) {
    }
}

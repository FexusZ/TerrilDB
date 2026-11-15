<?php

declare(strict_types=1);

namespace App\Core\Schema;

/**
 * Le compteur automatique d'une colonne (AUTO_INCREMENT, identité ou serial) tel que le catalogue le donne : la dernière valeur
 * attribuée, en décimal (un BIGINT UNSIGNED dépasse l'entier de PHP), et la borne propre au compteur quand elle existe (le
 * `max_value` d'une séquence PostgreSQL ; MySQL n'en a pas : seule la largeur du type borne). `sequence` nomme la séquence
 * (avec son schéma) et `identity` dit si elle suit le type de la colonne (identité) ou non (serial).
 */
final readonly class AutoCounter
{
    public function __construct(
        public string $table,
        public string $column,
        public string $lastValue,
        public ?string $maxValue = null,
        public ?string $sequence = null,
        public bool $identity = false,
    ) {
    }
}

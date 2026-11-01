<?php

declare(strict_types=1);

namespace App\Core\KeyValue;

use App\Core\KeyValue\Exception\KeyNotFoundException;

/**
 * Navigation dans une base clé-valeur : lister les clés sans bloquer le serveur (SCAN, jamais KEYS) et lire le contenu
 * d'une clé selon son type. Lecture seule : l'écriture passe par la console de commandes et son garde.
 */
interface KeyValueStore
{
    /**
     * @param string  $pattern motif glob de Redis (« * » = tout)
     * @param ?string $cursor  null au début ; ensuite le `nextCursor` de la page précédente
     * @param int     $count   indication de taille de tranche donnée au serveur, pas une garantie
     */
    public function scan(string $pattern, ?string $cursor, int $count): KeyPage;

    /**
     * @param int $limit nombre maximal de lignes de contenu rendues
     *
     * @throws KeyNotFoundException
     */
    public function inspect(string $key, int $limit): KeyDetail;

    /**
     * Le nombre de clés de la base courante.
     */
    public function size(): int;
}

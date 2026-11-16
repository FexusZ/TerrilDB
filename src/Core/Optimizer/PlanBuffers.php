<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

/**
 * Les pages (blocs) qu'un nœud a touchées, telles que PostgreSQL les compte avec BUFFERS : en cache (`sharedHit`), lues sur disque
 * (`sharedRead`), puis les fichiers temporaires (tri ou hachage qui déborde de la mémoire).
 */
final readonly class PlanBuffers
{
    public function __construct(
        public int $sharedHit,
        public int $sharedRead,
        public int $tempRead,
        public int $tempWritten,
    ) {
    }
}

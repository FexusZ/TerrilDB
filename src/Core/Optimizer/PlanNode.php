<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

/**
 * Un nœud d'un plan d'exécution. Les nombres (lignes, coût) sont les estimations du moteur, pas des mesures ; seuls les champs
 * `actual…`, `loops` et `buffers` (plan MESURÉ par EXPLAIN ANALYZE, voir `QueryPlan::$measured`) en sont.
 */
final readonly class PlanNode
{
    /**
     * @param list<PlanNode> $children
     * @param ?string        $writes       `insert`, `update` ou `delete` quand le nœud est l'écriture d'un EXPLAIN d'écriture
     *                                     (la table écrite est alors `relation`), sinon null
     * @param ?int           $actualRows   lignes réellement rendues, PAR exécution du nœud (comme `rows`, que l'on compare : le
     *                                     moteur moyenne sur les boucles) ; null hors plan mesuré
     * @param ?float         $actualTimeMs temps réel d'UNE exécution du nœud, en millisecondes
     * @param ?int           $loops        nombre d'exécutions du nœud (0 = jamais exécuté)
     * @param bool           $sortsOnDisk  tri qui a débordé sur disque (PostgreSQL « external merge », MariaDB passes de fusion) ;
     *                                     MySQL ne le dit pas
     */
    public function __construct(
        public PlanKind $kind,
        public string $label,
        public ?string $relation = null,
        public ?string $index = null,
        public ?int $rows = null,
        public ?float $cost = null,
        public ?string $condition = null,
        public bool $fullScan = false,
        public array $children = [],
        public ?string $writes = null,
        public ?int $actualRows = null,
        public ?float $actualTimeMs = null,
        public ?int $loops = null,
        public ?PlanBuffers $buffers = null,
        public bool $sortsOnDisk = false,
    ) {
    }
}

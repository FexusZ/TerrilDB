<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

/**
 * Le genre d'opération d'un plan, dans un vocabulaire commun aux moteurs ; le libellé exact du moteur reste dans `label`.
 */
enum PlanKind: string
{
    /** Parcours de toute la table (Seq Scan, ALL). */
    case FullScan = 'full_scan';
    /** Parcours de tout un index (type « index » de MySQL) : moins coûteux, mais complet. */
    case IndexFullScan = 'index_full_scan';
    /** Lecture ciblée par un index. */
    case IndexScan = 'index_scan';
    case Join = 'join';
    case Sort = 'sort';
    case Aggregate = 'aggregate';
    case Limit = 'limit';
    case Other = 'other';
}

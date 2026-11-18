<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

use App\Core\Optimizer\Exception\UnreadablePlanException;

/**
 * Lit la sortie d'un EXPLAIN ANALYZE : le même arbre que `PlanParser`, avec en plus ce que le moteur a mesuré en jouant la requête.
 */
interface MeasuredPlanParser
{
    /**
     * @param string $output la sortie telle que le moteur la rend (JSON, ou texte arborescent pour MySQL)
     *
     * @throws UnreadablePlanException
     */
    public function parseMeasured(string $output): QueryPlan;
}

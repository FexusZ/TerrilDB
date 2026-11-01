<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

use App\Core\Optimizer\Exception\UnreadablePlanException;

interface PlanParser
{
    /**
     * @param string $json le plan tel que le moteur le rend avec FORMAT JSON
     *
     * @throws UnreadablePlanException
     */
    public function parse(string $json): QueryPlan;
}

<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * L'arbre de blocage rendu : ses racines et un indicateur dit si des sessions ont été laissées de côté (bornes de nœuds ou de profondeur).
 */
final readonly class BlockingForest
{
    /**
     * @param list<BlockingNode> $roots
     */
    public function __construct(public array $roots, public bool $truncated)
    {
    }
}

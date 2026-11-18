<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

/**
 * Ce que la structure de la table dit d'une colonne visée par une condition : inconnu (pas de structure lisible : le linter garde
 * son conditionnel), sans index en tête (rien à perdre : la règle se tait) ou servie par un index ordinaire, dont on donne le nom.
 */
final readonly class IndexCheck
{
    private function __construct(public bool $known, public ?string $index)
    {
    }

    public static function unknown(): self
    {
        return new self(false, null);
    }

    public static function unserved(): self
    {
        return new self(true, null);
    }

    public static function servedBy(string $index): self
    {
        return new self(true, $index);
    }
}

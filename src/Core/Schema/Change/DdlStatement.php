<?php

declare(strict_types=1);

namespace App\Core\Schema\Change;

/**
 * Une instruction de définition de structure, prête à être exécutée, avec de quoi la montrer : ce qu'elle fait (`operation`,
 * un code stable que l'interface met en français), sur quoi (`subject`) et si elle détruit des données ou une structure
 * (`destructive` : à confirmer avant d'appliquer).
 */
final readonly class DdlStatement
{
    public function __construct(
        public string $sql,
        public string $operation,
        public string $subject,
        public bool $destructive,
    ) {
    }
}

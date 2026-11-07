<?php

declare(strict_types=1);

namespace App\Core\Schema\Change\Dialect;

use App\Core\Schema\Change\IndexDraft;
use App\Core\Schema\Change\Operation\AddColumn;

/**
 * Opération interne à MySQL : une colonne auto-incrémentée nouvelle et la clé primaire qui la porte, en une seule instruction.
 */
final readonly class MergedAddColumnAndKey
{
    public function __construct(
        public AddColumn $column,
        public IndexDraft $key,
    ) {
    }
}

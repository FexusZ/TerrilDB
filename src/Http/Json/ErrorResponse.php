<?php

declare(strict_types=1);

namespace App\Http\Json;

/**
 * Description d'une erreur à rendre au client : le code est stable, c'est lui que l'interface traduit.
 */
final readonly class ErrorResponse
{
    /**
     * @param array<string, bool|int|string> $extra
     * @param array<string, string>          $headers
     */
    public function __construct(
        public int $status,
        public string $code,
        public array $extra = [],
        public array $headers = [],
    ) {
    }
}

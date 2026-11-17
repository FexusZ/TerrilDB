<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * Une constatation de l'audit de configuration. `value` est ce que le serveur annonce (jamais une variable masquée) ;
 * `recommended` et `suggestion` sont des textes fixés par la règle : la suggestion est à LIRE, TerrilDB ne l'exécute jamais.
 */
final readonly class ConfigFinding
{
    public function __construct(
        public ConfigRule $rule,
        public Severity $severity,
        public string $variable,
        public string $value,
        public string $recommended,
        public ?string $suggestion,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Driver;

use App\Core\Connection\Engine;

/**
 * Source de vérité unique des capacités par moteur et version : version minimale à laquelle chacune apparaît (absente du
 * tableau = jamais). Une version inconnue ne revendique rien. Chaque ligne est rejouée contre les vraies cibles par
 * `CapabilityTest` : la table ne peut pas dériver quand une série change.
 *
 * Vitess (MySQL derrière vtgate) ne revendique rien : aucune de ces fonctions n'y a été vérifiée.
 */
final class CapabilityMatrix
{
    private const array MINIMUM = [
        'mysql' => [
            'recursive_cte' => [8, 0, 1],
            'lateral' => [8, 0, 14],
            'window_functions' => [8, 0, 2],
            'json_table' => [8, 0, 4],
            'skip_locked' => [8, 0, 1],
            'no_wait' => [8, 0, 1],
            'invisible_indexes' => [8, 0, 0],
            'actual_plan' => [8, 0, 18],
            'generated_columns' => [5, 7, 6],
            'enforced_check' => [8, 0, 16],
        ],
        'mariadb' => [
            'recursive_cte' => [10, 2, 2],
            'window_functions' => [10, 2, 0],
            'json_table' => [10, 6, 0],
            'system_versioning' => [10, 3, 4],
            'skip_locked' => [10, 6, 0],
            'no_wait' => [10, 3, 0],
            'invisible_indexes' => [10, 6, 0],
            'actual_plan' => [10, 1, 0],
            'generated_columns' => [10, 2, 0],
            'sequences' => [10, 3, 0],
            'enforced_check' => [10, 2, 1],
        ],
        'postgresql' => [
            'recursive_cte' => [8, 4, 0],
            'lateral' => [9, 3, 0],
            'window_functions' => [8, 4, 0],
            'json_table' => [17, 0, 0],
            'skip_locked' => [9, 5, 0],
            'no_wait' => [9, 5, 0],
            'actual_plan' => [9, 0, 0],
            'generated_columns' => [12, 0, 0],
            'sequences' => [9, 0, 0],
            'enforced_check' => [9, 0, 0],
        ],
        // Récursion et plan mesuré : constatés sur la série 26.10 de test, minimum plus ancien non vérifié.
        'clickhouse' => [
            'recursive_cte' => [26, 10, 0],
            'actual_plan' => [26, 10, 0],
            'window_functions' => [21, 3, 0],
            'generated_columns' => [20, 1, 0],
            'enforced_check' => [22, 1, 0],
        ],
        'redis' => [],
    ];

    public static function for(Engine $engine, ?ServerVersion $version): ServerCapabilities
    {
        if (null !== $version && Engine::MySql === $engine && ServerFlavour::MariaDb === $version->flavour) {
            $engine = Engine::MariaDb;
        }
        $supported = [];
        if (null !== $version && ServerFlavour::Vitess !== $version->flavour) {
            foreach (self::MINIMUM[$engine->value] as $name => [$major, $minor, $patch]) {
                if ($version->isAtLeast($major, $minor, $patch)) {
                    $supported[] = EngineCapability::from($name);
                }
            }
        }
        usort($supported, static fn (EngineCapability $a, EngineCapability $b): int => strcmp($a->value, $b->value));

        return new ServerCapabilities($engine, $version, $supported);
    }
}

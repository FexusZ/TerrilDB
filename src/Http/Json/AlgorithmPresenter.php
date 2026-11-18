<?php

declare(strict_types=1);

namespace App\Http\Json;

use App\Core\Schema\Change\Algorithm\AlgorithmAdvice;
use App\Core\Schema\Change\ChangePlan;

/**
 * Les instructions d'un aperçu avec, pour chacune, l'algorithme probable du moteur : les champs habituels (`ChangePlanPresenter`)
 * plus `algorithm` (instant | inplace | copy | rewrite | unknown), `blocksWrites` et `copiesTable` (nuls : non vérifié),
 * `estimatedExtraBytes` et `algorithmReason` (code stable de ce qui empêche de conclure). Des champs en plus, aucun retiré.
 */
final class AlgorithmPresenter
{
    /**
     * @param list<AlgorithmAdvice> $advice un avis par instruction, dans l'ordre du plan ; manquant = « unknown »
     *
     * @return list<array{sql: string, operation: string, subject: string, destructive: bool, algorithm: string, blocksWrites: ?bool, copiesTable: ?bool, estimatedExtraBytes: ?int, algorithmReason: ?string}>
     */
    public static function statements(ChangePlan $plan, array $advice): array
    {
        $out = [];
        foreach ($plan->statements as $position => $statement) {
            $one = $advice[$position] ?? AlgorithmAdvice::unknown('unverified_change');
            $out[] = ChangePlanPresenter::statement($statement) + [
                'algorithm' => $one->algorithm->value,
                'blocksWrites' => $one->blocksWrites,
                'copiesTable' => $one->copiesTable,
                'estimatedExtraBytes' => $one->estimatedExtraBytes,
                'algorithmReason' => $one->reason,
            ];
        }

        return $out;
    }
}

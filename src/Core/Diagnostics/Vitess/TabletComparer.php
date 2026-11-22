<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Vitess;

use App\Core\Diagnostics\VariableReport;

/**
 * Compare des tablets de la même base, en lecture seule. On part des variables MODIFIÉES de chaque tablet : une variable absente de la
 * liste de l'un est à son défaut sur celui-là. Un tablet qu'on n'a pas pu lire, ou dont les variables sont illisibles, ne compte pas pour
 * « au défaut » : il est simplement absent de la comparaison. Une variable masquée n'est jamais comparée ni montrée, seulement comptée.
 */
final class TabletComparer
{
    /**
     * @param list<TabletSnapshot> $snapshots
     */
    public function compare(array $snapshots, bool $truncated = false): TabletComparison
    {
        /** @var array<string, VariableReport> $reports alias => variables modifiées, des seuls tablets lus */
        $reports = [];
        foreach ($snapshots as $snapshot) {
            if (null !== $snapshot->variables && $snapshot->variables->status->isAvailable()) {
                $reports[$snapshot->tablet->alias] = $snapshot->variables;
            }
        }

        $names = [];
        $truncatedVariables = false;
        foreach ($reports as $report) {
            $truncatedVariables = $truncatedVariables || $report->truncated;
            foreach ($report->variables as $variable) {
                $names[$variable->name] = true;
            }
        }
        $names = array_map(strval(...), array_keys($names));
        sort($names, \SORT_STRING);

        $differences = [];
        $masked = 0;
        if (\count($reports) >= 2) {
            foreach ($names as $name) {
                $values = [];
                $digests = [];
                $unit = null;
                $isMasked = false;
                foreach ($reports as $alias => $report) {
                    $found = null;
                    foreach ($report->variables as $variable) {
                        if ($variable->name === $name) {
                            $found = $variable;

                            break;
                        }
                    }
                    $isMasked = $isMasked || ($found->masked ?? false);
                    $values[$alias] = $found?->value;
                    $digests[] = $found?->valueDigest;
                    $unit ??= $found?->unit;
                }
                if ($isMasked) {
                    ++$masked;

                    continue;
                }
                if (1 !== \count(array_unique($digests, \SORT_REGULAR))) {
                    $differences[] = new TabletVariableDifference($name, $values, $unit);
                }
            }
        }

        return new TabletComparison($snapshots, $differences, $masked, $truncated || $truncatedVariables);
    }

    /**
     * Les tablets à lire : ceux du keyspace demandé (tous si aucun), le primaire d'abord puis par alias, au plus `$max` (le reste est
     * annoncé par `truncated`, jamais lu : chaque tablet coûte une session).
     *
     * @return array{list<VitessTablet>, bool}
     */
    public static function select(TabletListing $listing, ?string $keyspace, int $max): array
    {
        $tablets = array_values(array_filter($listing->tablets, static fn (VitessTablet $t): bool => null === $keyspace || '' === $keyspace || $t->keyspace === $keyspace));
        usort($tablets, static fn (VitessTablet $a, VitessTablet $b): int => [$a->keyspace, $a->shard, 'PRIMARY' === $a->type ? 0 : 1, $a->alias] <=> [$b->keyspace, $b->shard, 'PRIMARY' === $b->type ? 0 : 1, $b->alias]);

        return [\array_slice($tablets, 0, $max), \count($tablets) > $max];
    }
}

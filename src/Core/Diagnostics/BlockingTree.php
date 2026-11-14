<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * Range des attentes à plat en arbre « qui bloque qui » : les racines sont les sessions qui bloquent sans attendre elles-mêmes.
 *
 * Les moteurs disent, pour chaque session qui attend, TOUTES celles qui la bloquent (le détenteur et celles qui font la queue
 * devant elle) : le graphe est donc souvent complet. Chaque session n'apparaît qu'UNE fois, sous un seul bloqueur : de
 * préférence un qui ne attend pas lui-même (le détenteur), sinon le dernier devant elle dans la file (celui qui attend depuis le
 * moins longtemps). L'arbre reste linéaire en nombre de nœuds, quelle que soit la file. Un cycle (que les moteurs défont
 * d'ordinaire en tuant une victime) n'a pas de racine : sa plus petite session en tient lieu. Bornes dures : `MAX_NODES` nœuds
 * et `MAX_DEPTH` niveaux, `truncated` dit que des sessions manquent.
 */
final class BlockingTree
{
    public const MAX_NODES = 500;
    public const MAX_DEPTH = 20;

    /**
     * @param list<LockWait> $waits
     *
     * @return list<BlockingNode>
     */
    public static function build(array $waits): array
    {
        return self::forest($waits)->roots;
    }

    /**
     * @param list<LockWait> $waits
     */
    public static function forest(array $waits): BlockingForest
    {
        /** @var array<string, list<LockWait>> $byWaiter */
        $byWaiter = [];
        /** @var array<string, LockParty> $parties */
        $parties = [];
        /** @var array<string, int> $age */
        $age = [];
        foreach ($waits as $wait) {
            $byWaiter[$wait->waiting->id][] = $wait;
            $parties[$wait->waiting->id] ??= $wait->waiting;
            $parties[$wait->blocking->id] ??= $wait->blocking;
            $age[$wait->waiting->id] = max($age[$wait->waiting->id] ?? 0, $wait->seconds);
        }

        // Un seul bloqueur par session qui attend.
        /** @var array<string, LockWait> $parent */
        $parent = [];
        foreach ($byWaiter as $id => $candidates) {
            $parent[(string) $id] = self::choose($candidates, $byWaiter, $age);
        }

        // Un cycle n'a pas de racine : sa plus petite session en devient une.
        $settled = [];
        $ids = array_map('strval', array_keys($parent));
        sort($ids, \SORT_STRING);
        foreach ($ids as $start) {
            $walk = [];
            $id = $start;
            while (isset($parent[$id]) && !isset($settled[$id])) {
                if (isset($walk[$id])) {
                    $cycle = \array_slice($walk, (int) array_search($id, array_keys($walk), true), null, true);
                    $members = array_map('strval', array_keys($cycle));
                    sort($members, \SORT_STRING);
                    unset($parent[$members[0]]);
                    break;
                }
                $walk[$id] = true;
                $id = $parent[$id]->blocking->id;
            }
            $settled += $walk;
        }

        /** @var array<string, list<LockWait>> $children */
        $children = [];
        foreach ($parent as $wait) {
            $children[$wait->blocking->id][] = $wait;
        }
        $rootIds = [];
        foreach (array_keys($parties) as $id) {
            if (!isset($parent[(string) $id])) {
                $rootIds[] = (string) $id;
            }
        }

        $sizes = [];
        foreach ($rootIds as $id) {
            self::size($id, $children, $sizes);
        }
        usort($rootIds, static fn (string $a, string $b): int => [$sizes[$b], $a] <=> [$sizes[$a], $b]);

        $budget = self::MAX_NODES;
        $truncated = false;
        $roots = [];
        foreach ($rootIds as $id) {
            if ($budget <= 0) {
                $truncated = true;
                break;
            }
            $roots[] = self::node($parties[$id], null, null, null, null, 1, $children, $parties, $budget, $truncated);
        }

        return new BlockingForest($roots, $truncated);
    }

    /**
     * @param list<LockWait>                $candidates
     * @param array<string, list<LockWait>> $byWaiter
     * @param array<string, int>            $age
     */
    private static function choose(array $candidates, array $byWaiter, array $age): LockWait
    {
        usort($candidates, static function (LockWait $a, LockWait $b) use ($byWaiter, $age): int {
            $key = static fn (LockWait $w): array => [isset($byWaiter[$w->blocking->id]) ? 1 : 0, $age[$w->blocking->id] ?? 0, -$w->seconds, $w->blocking->id];

            return $key($a) <=> $key($b);
        });

        return $candidates[0];
    }

    /**
     * @param array<string, list<LockWait>> $children
     * @param array<string, int>            $sizes
     */
    private static function size(string $id, array $children, array &$sizes): int
    {
        $size = 1;
        foreach ($children[$id] ?? [] as $wait) {
            $size += self::size($wait->waiting->id, $children, $sizes);
        }

        return $sizes[$id] = $size;
    }

    /**
     * @param array<string, list<LockWait>> $children
     * @param array<string, LockParty>      $parties
     */
    private static function node(LockParty $party, ?int $seconds, ?LockKind $kind, ?string $mode, ?string $object, int $depth, array $children, array $parties, int &$budget, bool &$truncated): BlockingNode
    {
        --$budget;
        $waits = $children[$party->id] ?? [];
        usort($waits, static fn (LockWait $a, LockWait $b): int => [$b->seconds, $a->waiting->id] <=> [$a->seconds, $b->waiting->id]);

        $nodes = [];
        foreach ($waits as $wait) {
            if ($depth >= self::MAX_DEPTH || $budget <= 0) {
                $truncated = true;
                break;
            }
            $nodes[] = self::node($wait->waiting, $wait->seconds, $wait->kind, $wait->mode, $wait->object, $depth + 1, $children, $parties, $budget, $truncated);
        }

        return new BlockingNode($party, $seconds, $kind, $mode, $object, $nodes);
    }
}

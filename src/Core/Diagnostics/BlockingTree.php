<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * Range des attentes à plat en arbre « qui bloque qui » : les racines sont les sessions qui bloquent sans attendre elles-mêmes.
 * Une session qui attend plusieurs autres figure sous chacune. Un cycle (que les moteurs défont d'ordinaire en tuant une
 * victime) ne boucle jamais : chaque session n'apparaît qu'une fois sur un même chemin.
 */
final class BlockingTree
{
    /**
     * @param list<LockWait> $waits
     *
     * @return list<BlockingNode>
     */
    public static function build(array $waits): array
    {
        /** @var array<string, list<LockWait>> $byBlocker */
        $byBlocker = [];
        /** @var array<string, true> $waiting */
        $waiting = [];
        /** @var array<string, LockParty> $parties */
        $parties = [];
        foreach ($waits as $wait) {
            $byBlocker[$wait->blocking->id][] = $wait;
            $waiting[$wait->waiting->id] = true;
            $parties[$wait->blocking->id] ??= $wait->blocking;
        }

        $rootIds = array_values(array_filter(array_keys($byBlocker), static fn (string $id): bool => !isset($waiting[$id])));
        $covered = [];
        $roots = array_map(static function (string $id) use ($byBlocker, $parties, &$covered): BlockingNode {
            return self::node($parties[$id], null, null, null, null, [$id => true], $byBlocker, $covered);
        }, $rootIds);

        // Un cycle n'a pas de racine : le premier membre non couvert en tient lieu.
        foreach (array_keys($byBlocker) as $id) {
            if (!isset($covered[$id])) {
                $roots[] = self::node($parties[$id], null, null, null, null, [$id => true], $byBlocker, $covered);
            }
        }

        usort($roots, static fn (BlockingNode $a, BlockingNode $b): int => [$b->count(), $a->party->id] <=> [$a->count(), $b->party->id]);

        return $roots;
    }

    /**
     * @param array<string, true>           $path
     * @param array<string, list<LockWait>> $byBlocker
     * @param array<string, true>           $covered
     */
    private static function node(LockParty $party, ?int $seconds, ?LockKind $kind, ?string $mode, ?string $object, array $path, array $byBlocker, array &$covered): BlockingNode
    {
        $covered[$party->id] = true;
        $children = [];
        foreach ($byBlocker[$party->id] ?? [] as $wait) {
            if (isset($path[$wait->waiting->id])) {
                continue;
            }
            $children[] = self::node($wait->waiting, $wait->seconds, $wait->kind, $wait->mode, $wait->object, $path + [$wait->waiting->id => true], $byBlocker, $covered);
        }
        usort($children, static fn (BlockingNode $a, BlockingNode $b): int => [$b->seconds ?? -1, $a->party->id] <=> [$a->seconds ?? -1, $b->party->id]);

        return new BlockingNode($party, $seconds, $kind, $mode, $object, $children);
    }
}

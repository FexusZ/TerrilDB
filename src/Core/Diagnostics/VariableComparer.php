<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * Compare les variables de deux serveurs du même moteur, en lecture seule. On retient chaque variable MODIFIÉE (par rapport au défaut
 * du moteur) d'un côté ou de l'autre, puis on relit ces noms-là des deux côtés : une variable laissée à son défaut sur un serveur et
 * modifiée sur l'autre apparaît donc avec ses deux valeurs. Deux valeurs par défaut qui diffèrent parce que les versions diffèrent
 * ne sont pas un écart de configuration et ne sont pas listées. Une variable dont la valeur est masquée d'un côté ou de l'autre n'est
 * jamais comparée ni montrée.
 */
final class VariableComparer
{
    public function compare(VariableDiagnostics $a, VariableDiagnostics $b): VariableComparison
    {
        $changedQuery = new VariableQuery(changedOnly: true, limit: VariableQuery::MAX_LIMIT);
        $changedA = $a->variables($changedQuery);
        if (!$changedA->status->isAvailable()) {
            return new VariableComparison($changedA->status, 'a', [], 0, false);
        }
        $changedB = $b->variables($changedQuery);
        if (!$changedB->status->isAvailable()) {
            return new VariableComparison($changedB->status, 'b', [], 0, false);
        }

        $names = [];
        foreach ([...$changedA->variables, ...$changedB->variables] as $variable) {
            $names[$variable->name] = true;
        }
        $names = array_map(strval(...), array_keys($names));
        sort($names, \SORT_STRING);
        $truncated = $changedA->truncated || $changedB->truncated || \count($names) > VariableQuery::MAX_NAMES;
        $names = \array_slice($names, 0, VariableQuery::MAX_NAMES);

        $query = new VariableQuery(limit: VariableQuery::MAX_LIMIT, names: $names);
        $fullA = $this->byName($a->variables($query));
        $fullB = $this->byName($b->variables($query));

        $differences = [];
        $masked = 0;
        foreach ($names as $name) {
            $va = $fullA[$name] ?? null;
            $vb = $fullB[$name] ?? null;
            if ($va?->masked || $vb?->masked) {
                ++$masked;

                continue;
            }
            // Comparées sur la valeur COMPLÈTE (empreinte) : deux textes qui ne diffèrent qu'après la coupure d'affichage ne sont pas égaux.
            if (null !== $va && null !== $vb && $va->valueDigest === $vb->valueDigest) {
                continue;
            }
            $beyondDisplay = null !== $va && null !== $vb && $va->value === $vb->value;
            $differences[] = new VariableDifference($name, null !== $va, $va?->value, $va->changed ?? false, null !== $vb, $vb?->value, $vb->changed ?? false, $va->unit ?? $vb->unit ?? null, $beyondDisplay);
        }

        return new VariableComparison(DiagnosticStatus::available(), null, $differences, $masked, $truncated);
    }

    /**
     * @return array<string, ServerVariable>
     */
    private function byName(VariableReport $report): array
    {
        $byName = [];
        foreach ($report->variables as $variable) {
            $byName[$variable->name] = $variable;
        }

        return $byName;
    }
}

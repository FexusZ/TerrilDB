<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\LockReason;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;

/**
 * La seule table d'une requête simple et la manière de la lire : le chargeur n'est appelé qu'UNE fois, au premier besoin, et tout
 * échec (table inconnue, droit manquant, moteur sans introspection) rend « structure inconnue », jamais une erreur. Une vue n'a
 * pas d'index à elle : elle reste inconnue.
 *
 * Les index jugés sont les ordinaires : un index dont le modèle ne rend pas fidèlement les colonnes (expression : le texte de
 * l'expression est omis, la place de la colonne n'est donc pas sûre), plein texte, spatial, à méthode d'accès particulière
 * (hachage, GIN…) ou invisible n'est jamais cité.
 */
final class LintScope
{
    private bool $loaded = false;
    private ?TableDefinition $table = null;

    /**
     * @param list<string>                       $names  les noms qui désignent la table dans la requête (nom, alias), en minuscules
     * @param \Closure(string): ?TableDefinition $loader
     */
    public function __construct(private readonly string $name, private readonly array $names, private readonly \Closure $loader)
    {
    }

    public function table(): ?TableDefinition
    {
        if (!$this->loaded) {
            $this->loaded = true;

            try {
                $table = ($this->loader)($this->name);
            } catch (\Throwable) {
                $table = null;
            }
            $this->table = null !== $table && TableKind::Table === $table->kind ? $table : null;
        }

        return $this->table;
    }

    public function check(string $column, ?string $qualifier): IndexCheck
    {
        $table = $this->table();
        if (null === $table) {
            return IndexCheck::unknown();
        }
        if (null !== $qualifier && !\in_array(strtolower($qualifier), $this->names, true)) {
            return IndexCheck::unserved();
        }
        $wanted = strtolower($column);
        $known = false;
        foreach ($table->columns as $candidate) {
            $known = $known || strtolower($candidate->name) === $wanted;
        }
        if (!$known) {
            return IndexCheck::unserved();
        }
        foreach ($table->indexes as $index) {
            if (strtolower($index->columns[0]) === $wanted && self::isOrdinary($index->lock)) {
                return IndexCheck::servedBy($index->name);
            }
        }

        return IndexCheck::unserved();
    }

    /**
     * La colonne (nom seul) existe-t-elle dans la table lue ? Sa définition, ou null.
     */
    public function column(string $column, ?string $qualifier): ?ColumnDefinition
    {
        $table = $this->table();
        if (null === $table || (null !== $qualifier && !\in_array(strtolower($qualifier), $this->names, true))) {
            return null;
        }
        foreach ($table->columns as $candidate) {
            if (strtolower($candidate->name) === strtolower($column)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Un index à méthode d'accès particulière (hachage, GIN…) en tête sur la colonne : il peut servir une égalité, donc une branche d'OR.
     */
    public function hasAccessMethodIndex(string $column, ?string $qualifier): bool
    {
        $table = $this->table();
        if (null === $table || (null !== $qualifier && !\in_array(strtolower($qualifier), $this->names, true))) {
            return false;
        }
        foreach ($table->indexes as $index) {
            if (LockReason::AccessMethod === $index->lock && strtolower($index->columns[0]) === strtolower($column)) {
                return true;
            }
        }

        return false;
    }

    private static function isOrdinary(?LockReason $lock): bool
    {
        return !\in_array($lock, [LockReason::Expression, LockReason::Fulltext, LockReason::Spatial, LockReason::AccessMethod, LockReason::IndexInvisible, LockReason::IndexIgnored], true);
    }
}

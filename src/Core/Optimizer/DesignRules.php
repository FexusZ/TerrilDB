<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

use App\Core\Connection\Engine;
use App\Core\Schema\TableDefinition;

/**
 * Règles de CONCEPTION de l'audit de schéma (suite de #78) : ce qu'une table dit d'elle-même, jugé sans exécuter quoi que ce
 * soit. Chaque règle préfère se taire que se tromper : une information absente (jeu de caractères inconnu, table voisine non
 * lue) ou ambiguë ne donne jamais de constat. Les suggestions sont du texte à relire ; un nom d'objet qui ne peut pas être
 * cité sans risque (caractère de contrôle) n'en reçoit pas.
 */
final class DesignRules
{
    /**
     * @param list<TableDefinition> $tables toutes les tables lues (les voisines servent aux règles de relation)
     *
     * @return list<Finding>
     */
    public function check(Engine $engine, TableDefinition $table, array $tables): array
    {
        $findings = [];
        if (Engine::MySql === $engine || Engine::MariaDb === $engine) {
            array_push($findings, ...$this->legacyCharacterSet($engine, $table));
        }

        return $findings;
    }

    /**
     * utf8 et utf8mb3 : trois octets par caractère, donc ni émojis ni une partie des idéogrammes (erreur 1366), et l'alias
     * « utf8 » est déprécié. Une seule constatation par table, avec toutes les colonnes concernées.
     *
     * @return list<Finding>
     */
    private function legacyCharacterSet(Engine $engine, TableDefinition $table): array
    {
        $columns = [];
        foreach ($table->columns as $column) {
            if (\in_array($column->characterSet, ['utf8', 'utf8mb3'], true)) {
                $columns[] = $column->name;
            }
        }
        if ([] === $columns) {
            return [];
        }

        $name = self::quote($engine, $table->name);

        return [new Finding(
            FindingRule::LegacyCharacterSet,
            Severity::Warning,
            $table->name,
            $columns,
            suggestion: null === $name ? null : \sprintf('ALTER TABLE %s CONVERT TO CHARACTER SET utf8mb4;', $name),
        )];
    }

    /**
     * Un identifiant cité, ou null s'il porte un caractère de contrôle (retour à la ligne, NUL…) qui ferait dire autre chose
     * au texte montré.
     */
    private static function quote(Engine $engine, string $identifier): ?string
    {
        if (1 === preg_match('/[\x00-\x1F\x7F]/', $identifier)) {
            return null;
        }

        return Engine::PostgreSql === $engine ? '"'.str_replace('"', '""', $identifier).'"' : '`'.str_replace('`', '``', $identifier).'`';
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Schema\Change\Algorithm;

/**
 * La table de règles, PURE : (jeu de règles, geste) -> verdict. Chaque ligne affirmative est vérifiée par
 * tests/Integration/Driver/AlterAlgorithmTest, qui exécute l'ALTER sur une table jetable avec ALGORITHM=INSTANT, puis INPLACE,
 * puis COPY (MySQL, MariaDB) ou compare le fichier de la table avant et après (PostgreSQL). Ce qui n'y figure pas est « unknown » :
 * jamais une certitude sans vérification.
 *
 * Écarts entre moteurs, vus par les essais : MariaDB renomme un index, retire une clé étrangère et renomme une colonne indexée sans
 * toucher aux données (instantané), MySQL le fait en place ; MySQL n'accepte « NULL vers NOT NULL » en place que sous un sql_mode
 * strict, MariaDB toujours.
 */
final class AlterAlgorithmRules
{
    public static function verdict(RuleFamily $family, AlterChange $change): RuleVerdict
    {
        return match ($family) {
            RuleFamily::MySql, RuleFamily::MariaDb => self::mySqlFamily($family, $change),
            RuleFamily::PostgreSql => self::postgreSql($change),
        };
    }

    private static function mySqlFamily(RuleFamily $family, AlterChange $change): RuleVerdict
    {
        $instant = RuleVerdict::of(AlterAlgorithm::Instant, false, false);
        $inplace = RuleVerdict::of(AlterAlgorithm::Inplace, false, null);
        $copy = RuleVerdict::of(AlterAlgorithm::Copy, true, true);
        $mariaDb = RuleFamily::MariaDb === $family;

        return match ($change) {
            AlterChange::AddColumn, AlterChange::DropColumn, AlterChange::RenameColumn, AlterChange::ChangeDefault, AlterChange::AppendEnumMembers, AlterChange::RenameTable => $instant,
            AlterChange::DropNotNull, AlterChange::AddIndex, AlterChange::DropIndex, AlterChange::AddPrimaryKey => $inplace,
            AlterChange::ChangeType, AlterChange::AddForeignKey, AlterChange::DropPrimaryKey => $copy,
            AlterChange::RenameIndex, AlterChange::DropForeignKey => $mariaDb ? $instant : $inplace,
            AlterChange::RenameIndexedColumn => $mariaDb ? $instant : RuleVerdict::unknown('unverified_change'),
            AlterChange::SetNotNull => $mariaDb ? $inplace : RuleVerdict::unknown('depends_on_sql_mode'),
            AlterChange::WidenVarchar, AlterChange::AddColumnWithVolatileDefault => RuleVerdict::unknown('unverified_change'),
        };
    }

    /**
     * PostgreSQL : « instant » = aucune réécriture et aucun parcours (le fichier de la table ne change pas), « inplace » = pas de
     * réécriture mais un parcours ou une construction d'index qui garde un verrou bloquant les écritures, « rewrite » = nouvelle
     * copie de la table. Le verrou d'un changement instantané est bref : son caractère bloquant n'est pas affirmé.
     */
    private static function postgreSql(AlterChange $change): RuleVerdict
    {
        $instant = RuleVerdict::of(AlterAlgorithm::Instant, null, false);
        $scan = RuleVerdict::of(AlterAlgorithm::Inplace, true, false);
        $rewrite = RuleVerdict::of(AlterAlgorithm::Rewrite, true, true);

        return match ($change) {
            AlterChange::AddColumn, AlterChange::DropColumn, AlterChange::RenameColumn, AlterChange::RenameIndexedColumn,
            AlterChange::ChangeDefault, AlterChange::DropNotNull, AlterChange::WidenVarchar, AlterChange::DropIndex, AlterChange::RenameIndex,
            AlterChange::DropForeignKey, AlterChange::RenameTable => $instant,
            AlterChange::SetNotNull, AlterChange::AddIndex, AlterChange::AddForeignKey => $scan,
            AlterChange::ChangeType, AlterChange::AddColumnWithVolatileDefault => $rewrite,
            AlterChange::AppendEnumMembers, AlterChange::AddPrimaryKey, AlterChange::DropPrimaryKey => RuleVerdict::unknown('unverified_change'),
        };
    }
}

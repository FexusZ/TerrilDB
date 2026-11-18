<?php

declare(strict_types=1);

namespace App\Core\Schema\Change\Algorithm;

use App\Core\Connection\Engine;
use App\Core\Driver\ServerVersion;
use App\Core\Schema\Change\ChangePlan;
use App\Core\Schema\Change\DdlStatement;
use App\Core\Schema\Change\DefaultKind;
use App\Core\Schema\Change\DefaultValue;
use App\Core\Schema\Change\Exception\InvalidSchemaChangeException;
use App\Core\Schema\Change\Operation\AddColumn;
use App\Core\Schema\Change\Operation\DropIndex;
use App\Core\Schema\Change\Operation\ModifyColumn;
use App\Core\Schema\Change\Operation\SetPrimaryKey;
use App\Core\Schema\Change\SchemaDiffer;
use App\Core\Schema\Change\TableDraft;
use App\Core\Schema\TableDefinition;

/**
 * Dit, pour chaque instruction d'un plan, ce que le moteur en fera probablement (`AlgorithmAdvice`), d'après la table de règles
 * (`AlterAlgorithmRules`) et la forme du changement. LECTURE SEULE : rien n'est essayé sur le serveur, l'essai se fait dans les
 * tests contre des tables jetables. Au moindre doute (version non vérifiée, table qui n'est pas InnoDB, geste ou type hors des
 * règles vérifiées) le verdict est `unknown` avec sa raison, jamais une certitude.
 */
final class AlterAlgorithmAdvisor
{
    private const string SIMPLE_TYPES = '/^(?:tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double|char|varchar|binary|varbinary|tinytext|text|mediumtext|longtext|tinyblob|blob|mediumblob|longblob|date|time|datetime|timestamp|year|json|enum)\b/i';

    public function __construct(private readonly SchemaDiffer $differ = new SchemaDiffer())
    {
    }

    /**
     * @return list<AlgorithmAdvice> un avis par instruction du plan, dans le même ordre
     */
    public function advise(Engine $engine, ?ServerVersion $version, TableDefinition $current, ?TableDraft $draft, ChangePlan $plan): array
    {
        if (null === $draft) {
            return array_map(static fn (): AlgorithmAdvice => AlgorithmAdvice::unknown('not_an_alter'), $plan->statements);
        }
        $family = $this->gate($engine, $version, $current);
        if (!$family instanceof RuleFamily) {
            return array_map(static fn (): AlgorithmAdvice => AlgorithmAdvice::unknown($family), $plan->statements);
        }

        try {
            $operations = $this->differ->diff($current, $draft);
        } catch (InvalidSchemaChangeException) {
            $operations = [];
        }
        $remaining = $this->remainingIndexedColumns($current, $operations);

        return array_map(fn (DdlStatement $statement): AlgorithmAdvice => $this->adviseStatement($family, $current, $statement, $operations, $remaining), $plan->statements);
    }

    /**
     * @return RuleFamily|string le jeu de règles, ou le code de ce qui empêche d'en avoir un
     */
    private function gate(Engine $engine, ?ServerVersion $version, TableDefinition $current): RuleFamily|string
    {
        if (!\in_array($engine, [Engine::MySql, Engine::MariaDb, Engine::PostgreSql], true)) {
            return 'unsupported_engine';
        }
        $family = RuleFamily::for($engine, $version);
        if (null === $family) {
            return 'unverified_version';
        }
        if ($family->isMySqlFamily() && 'innodb' !== strtolower($current->storageEngine ?? '')) {
            return 'not_innodb';
        }

        return $family;
    }

    /**
     * Les colonnes qui restent dans un index quand les instructions qui retirent des index ont été jouées : l'ordre du plan
     * retire d'abord clés étrangères, index et clé primaire, puis touche aux colonnes.
     *
     * @param list<object> $operations
     *
     * @return array<string, true>
     */
    private function remainingIndexedColumns(TableDefinition $current, array $operations): array
    {
        $dropped = [];
        $primaryDropped = false;
        foreach ($operations as $operation) {
            if ($operation instanceof DropIndex) {
                $dropped[$operation->name] = true;
            }
            if ($operation instanceof SetPrimaryKey && null !== $operation->old) {
                $primaryDropped = true;
            }
        }
        $columns = [];
        foreach ($current->indexes as $index) {
            if (isset($dropped[$index->name]) || ($index->primary && $primaryDropped)) {
                continue;
            }
            foreach ($index->columns as $column) {
                $columns[$column] = true;
            }
        }

        return $columns;
    }

    /**
     * @param list<object>        $operations
     * @param array<string, true> $remaining
     */
    private function adviseStatement(RuleFamily $family, TableDefinition $current, DdlStatement $statement, array $operations, array $remaining): AlgorithmAdvice
    {
        $change = $this->changeOf($family, $statement, $operations, $remaining);
        if ($change instanceof RuleVerdict) {
            return $this->advice($change, $current);
        }
        if (null === $change) {
            return AlgorithmAdvice::unknown('unverified_change');
        }

        return $this->advice(AlterAlgorithmRules::verdict($family, $change), $current);
    }

    /**
     * Le geste de la table de règles, ou un verdict déjà rendu (plusieurs gestes sur une colonne), ou nul si on ne sait pas.
     *
     * @param list<object>        $operations
     * @param array<string, true> $remaining
     */
    private function changeOf(RuleFamily $family, DdlStatement $statement, array $operations, array $remaining): AlterChange|RuleVerdict|null
    {
        $subject = $statement->subject;

        return match ($statement->operation) {
            'add_column' => $this->addColumn($family, $subject, $operations),
            // Une colonne qu'on retire ne peut plus figurer dans un index du brouillon : si le plan la laisse indexée, on ne conclut pas.
            'drop_column' => isset($remaining[$subject]) ? null : AlterChange::DropColumn,
            'rename_column' => isset($remaining[$subject]) ? AlterChange::RenameIndexedColumn : AlterChange::RenameColumn,
            'modify_column' => $this->modifyColumn($family, $subject, $operations),
            'add_index' => AlterChange::AddIndex,
            'drop_index' => AlterChange::DropIndex,
            'rename_index' => AlterChange::RenameIndex,
            'add_foreign_key' => AlterChange::AddForeignKey,
            'drop_foreign_key' => AlterChange::DropForeignKey,
            'add_primary_key' => AlterChange::AddPrimaryKey,
            'drop_primary_key' => AlterChange::DropPrimaryKey,
            'rename_table' => AlterChange::RenameTable,
            default => null,
        };
    }

    /**
     * @param list<object> $operations
     */
    private function addColumn(RuleFamily $family, string $name, array $operations): ?AlterChange
    {
        $add = null;
        foreach ($operations as $operation) {
            if ($operation instanceof AddColumn && $operation->column->name === $name) {
                $add = $operation->column;
            }
        }
        if (null === $add || $add->autoIncrement) {
            return null;
        }
        $default = $add->defaultValue();
        $kind = $default?->kind;
        $volatile = false;
        if (DefaultKind::Expression === $kind) {
            if (self::isTimestampExpression($default)) {
                $volatile = false;
            } elseif (RuleFamily::PostgreSql === $family && self::isVolatileExpression($default)) {
                $volatile = true;
            } elseif (RuleFamily::PostgreSql === $family && self::isStableExpression($default)) {
                $volatile = false;
            } else {
                return null;
            }
        }
        if ($family->isMySqlFamily() && 1 !== preg_match(self::SIMPLE_TYPES, $add->type)) {
            return null;
        }
        if (RuleFamily::PostgreSql === $family && 1 !== preg_match('/^(?:smallint|integer|int|bigint|numeric|decimal|real|double precision|boolean|text|character varying|varchar|char|character|date|time|timestamp|timestamptz|uuid|jsonb?)\b/i', $add->type)) {
            return null;
        }

        return $volatile ? AlterChange::AddColumnWithVolatileDefault : AlterChange::AddColumn;
    }

    private static function isTimestampExpression(?DefaultValue $default): bool
    {
        return null !== $default && 1 === preg_match('/^(?:now\(\)|current_timestamp(?:\(\d\))?|localtimestamp(?:\(\d\))?)$/', $default->value);
    }

    private static function isStableExpression(?DefaultValue $default): bool
    {
        return null !== $default && 1 === preg_match('/^(?:true|false|current_date|current_time(?:\(\d\))?)$/', $default->value);
    }

    private static function isVolatileExpression(?DefaultValue $default): bool
    {
        return null !== $default && 1 === preg_match('/^(?:uuid\(\)|gen_random_uuid\(\)|uuid_generate_v4\(\))$/', $default->value);
    }

    /**
     * @param list<object> $operations
     */
    private function modifyColumn(RuleFamily $family, string $name, array $operations): ?RuleVerdict
    {
        $modify = null;
        foreach ($operations as $operation) {
            if ($operation instanceof ModifyColumn && $operation->after->name === $name) {
                $modify = $operation;
            }
        }
        if (null === $modify || $modify->autoIncrementChanged) {
            return null;
        }

        $changes = [];
        if ($modify->typeChanged) {
            $type = $family->isMySqlFamily()
                ? TypeChangeClassifier::forMySqlFamily($modify->before->type, $modify->after->type)
                : TypeChangeClassifier::forPostgreSql($modify->before->type, $modify->after->type);
            if (null === $type) {
                return null;
            }
            $changes[] = $type;
        }
        if ($modify->nullabilityChanged) {
            $changes[] = $modify->after->nullable ? AlterChange::DropNotNull : AlterChange::SetNotNull;
        }
        if ($modify->defaultChanged) {
            $changes[] = AlterChange::ChangeDefault;
        }
        if ([] === $changes) {
            return null;
        }

        return self::worst($family, $changes);
    }

    /**
     * Plusieurs gestes dans une seule instruction : le pire des verdicts. Une copie l'emporte sur tout (elle recopie quoi que fassent
     * les autres gestes) ; sinon, si l'un est inconnu, le tout l'est.
     *
     * @param non-empty-list<AlterChange> $changes
     */
    private static function worst(RuleFamily $family, array $changes): RuleVerdict
    {
        $worst = null;
        $unknown = null;
        foreach ($changes as $change) {
            $verdict = AlterAlgorithmRules::verdict($family, $change);
            if (AlterAlgorithm::Unknown === $verdict->algorithm) {
                $unknown ??= $verdict;
                continue;
            }
            if (null === $worst || $verdict->algorithm->cost() > $worst->algorithm->cost()) {
                $worst = $verdict;
            }
        }
        if (null !== $worst && $worst->algorithm->copiesWholeTable()) {
            return $worst;
        }

        return $unknown ?? $worst;
    }

    private function advice(RuleVerdict $verdict, TableDefinition $current): AlgorithmAdvice
    {
        if (AlterAlgorithm::Unknown === $verdict->algorithm) {
            return AlgorithmAdvice::unknown($verdict->reason ?? 'unverified_change');
        }

        return new AlgorithmAdvice(
            $verdict->algorithm,
            $verdict->blocksWrites,
            $verdict->copiesTable,
            $verdict->algorithm->copiesWholeTable() ? self::extraBytes($current) : null,
        );
    }

    private static function extraBytes(TableDefinition $table): ?int
    {
        if (null === $table->dataBytes && null === $table->indexBytes) {
            return null;
        }

        return ($table->dataBytes ?? 0) + ($table->indexBytes ?? 0);
    }
}

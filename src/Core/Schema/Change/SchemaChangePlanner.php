<?php

declare(strict_types=1);

namespace App\Core\Schema\Change;

use App\Core\Connection\Engine;
use App\Core\Schema\Change\Dialect\ClickHouseDdlDialect;
use App\Core\Schema\Change\Dialect\DdlDialect;
use App\Core\Schema\Change\Dialect\MySqlDdlDialect;
use App\Core\Schema\Change\Dialect\PostgreSqlDdlDialect;
use App\Core\Schema\Change\Exception\InvalidSchemaChangeException;
use App\Core\Schema\Change\Operation\DropTable;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;

/**
 * Du brouillon de table aux instructions : compare à la table réelle (`SchemaDiffer`), puis laisse le dialecte du moteur écrire
 * le SQL. N'écrit rien nulle part : c'est ce qu'on montre à l'utilisateur avant qu'il valide.
 */
final class SchemaChangePlanner
{
    public function __construct(private readonly SchemaDiffer $differ = new SchemaDiffer())
    {
    }

    /**
     * @throws InvalidSchemaChangeException
     */
    public function plan(Engine $engine, TableDefinition $current, TableDraft $draft): ChangePlan
    {
        $dialect = $this->dialect($engine);
        $this->assertTable($current);
        $draft = $dialect->prepare($current, $draft);

        return new ChangePlan($dialect->statements($current->name, $this->differ->diff($current, $draft)), $dialect->transactional());
    }

    /**
     * @throws InvalidSchemaChangeException
     */
    public function planDrop(Engine $engine, TableDefinition $current): ChangePlan
    {
        $dialect = $this->dialect($engine);
        $this->assertTable($current);

        return new ChangePlan($dialect->statements($current->name, [new DropTable($current->name)]), $dialect->transactional());
    }

    /**
     * Empreinte de la structure (colonnes, index, clés étrangères), SANS l'estimation du nombre de lignes qui bouge seule. Le
     * client la renvoie à l'application : si elle a changé entre-temps, quelqu'un d'autre a modifié la table, on refuse.
     */
    public static function fingerprint(TableDefinition $table): string
    {
        return hash('sha256', json_encode([
            $table->name,
            array_map(static fn ($c): array => [$c->name, $c->type, $c->nullable, $c->default, $c->autoIncrement], $table->columns),
            array_map(static fn ($i): array => [$i->name, $i->columns, $i->unique, $i->primary], $table->indexes),
            array_map(static fn ($f): array => [$f->name, $f->columns, $f->referencedTable, $f->referencedColumns, $f->onUpdate->value, $f->onDelete->value], $table->foreignKeys),
        ], \JSON_THROW_ON_ERROR));
    }

    private function dialect(Engine $engine): DdlDialect
    {
        return match ($engine) {
            Engine::PostgreSql => new PostgreSqlDdlDialect(),
            Engine::MySql, Engine::MariaDb => new MySqlDdlDialect(),
            Engine::ClickHouse => new ClickHouseDdlDialect(),
            Engine::Redis => throw new InvalidSchemaChangeException('This engine has no schema to edit.'),
        };
    }

    private function assertTable(TableDefinition $current): void
    {
        if (TableKind::Table !== $current->kind) {
            throw new InvalidSchemaChangeException('Only a table can be edited, not a view or a sequence.');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Schema\Change\Dialect;

use App\Core\Schema\Change\DdlStatement;
use App\Core\Schema\Change\Exception\InvalidSchemaChangeException;
use App\Core\Schema\Change\TableDraft;
use App\Core\Schema\TableDefinition;

/**
 * Écrit en SQL, pour un moteur, les opérations que `SchemaDiffer` a trouvées.
 */
interface DdlDialect
{
    /**
     * Le brouillon tel que ce moteur le comprend (ex. ClickHouse écrit la nullabilité dans le type) ; refuse ce que le moteur
     * ne sait pas faire.
     *
     * @throws InvalidSchemaChangeException
     */
    public function prepare(TableDefinition $current, TableDraft $draft): TableDraft;

    /**
     * Les instructions, dans un ordre où aucune ne dépend d'une autre pas encore exécutée.
     *
     * @param list<object> $operations
     *
     * @return list<DdlStatement>
     *
     * @throws InvalidSchemaChangeException
     */
    public function statements(string $table, array $operations): array;

    /**
     * Le moteur annule tout si une instruction échoue (DDL transactionnel) : tout ou rien. Sinon chaque instruction est
     * validée toute seule et un échec laisse celles d'avant appliquées.
     */
    public function transactional(): bool;
}

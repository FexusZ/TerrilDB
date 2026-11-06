<?php

declare(strict_types=1);

namespace App\Core\Schema;

use App\Core\Driver\Exception\UnsupportedOperationException;
use App\Core\Schema\Exception\TableNotFoundException;

/**
 * Lecture de la structure d'une base cible. Un introspecteur par moteur, tous rendent le même modèle.
 * Le périmètre est le schéma par défaut de la connexion (la base pour MySQL et MariaDB, le schéma courant
 * pour PostgreSQL).
 */
interface SchemaIntrospector
{
    /**
     * Les bases que le compte peut voir sur le serveur, triées à l'octet. Une autre base s'explore en ouvrant la
     * connexion sur elle (voir TargetOpener) : une connexion reste une base, y compris sur PostgreSQL.
     *
     * @return list<DatabaseSummary>
     */
    public function listDatabases(): array;

    /**
     * Les schémas de la base ouverte, triés à l'octet (PostgreSQL seulement : MySQL, MariaDB et ClickHouse n'ont pas de niveau
     * entre la base et les tables). Un schéma s'explore en ouvrant la connexion avec lui (voir TargetOpener).
     *
     * @return list<SchemaSummary>
     *
     * @throws UnsupportedOperationException
     */
    public function listSchemas(): array;

    /**
     * Tables et vues, triées par nom.
     *
     * @return list<TableSummary>
     */
    public function listTables(): array;

    /**
     * @throws TableNotFoundException
     */
    public function describeTable(string $name): TableDefinition;
}

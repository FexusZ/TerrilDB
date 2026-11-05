<?php

declare(strict_types=1);

namespace App\Core\Schema;

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
     * Tables et vues, triées par nom.
     *
     * @return list<TableSummary>
     */
    public function listTables(): array;

    /**
     * Une page de tables, paginée et filtrée par le moteur (clé de tri à l'octet et LIMIT, jamais en tranchant une liste
     * complète) : pour les bases à des milliers de tables.
     */
    public function listTablesPage(TablePageRequest $request): TablePage;

    /**
     * @throws TableNotFoundException
     */
    public function describeTable(string $name): TableDefinition;
}

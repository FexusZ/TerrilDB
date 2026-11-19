<?php

declare(strict_types=1);

namespace App\Driver\MySql;

use App\Core\Connection\Engine;
use App\Core\Driver\Exception\UnsupportedOperationException;
use App\Core\Schema\AutoCounter;
use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\DatabaseSummary;
use App\Core\Schema\DefinerReport;
use App\Core\Schema\Exception\TableNotFoundException;
use App\Core\Schema\ForeignKeyDefinition;
use App\Core\Schema\IndexDefinition;
use App\Core\Schema\IndexUsage;
use App\Core\Schema\IndexUsageReport;
use App\Core\Schema\LockReason;
use App\Core\Schema\OperationsSource;
use App\Core\Schema\ReferentialAction;
use App\Core\Schema\SchemaIntrospector;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;
use App\Core\Schema\TablePage;
use App\Core\Schema\TablePageRequest;
use App\Core\Schema\TableSummary;
use App\Core\Schema\UpkeepReport;
use App\Driver\RowReader;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;

/**
 * Introspection de MySQL et MariaDB par information_schema, dans la base de la connexion. Les noms de tables
 * ne sont jamais concaténés au SQL : ils passent en paramètres liés.
 *
 * Les index fonctionnels (expressions) n'ont pas de nom de colonne dans information_schema : leurs parties
 * sont ignorées, et un index qui n'a plus aucune colonne nommée n'est pas listé.
 */
final readonly class MySqlIntrospector implements SchemaIntrospector, OperationsSource
{
    /**
     * @param bool $vitess la cible est un vtgate (`ServerFlavour::Vitess`, lue une fois à l'ouverture) : ses « bases » sont des keyspaces
     */
    public function __construct(private Connection $connection, private bool $vitess = false)
    {
    }

    /**
     * Les bases physiques d'un tablet Vitess (`_vt`, `vt_<keyspace>_<partition>` : « vt_shop_0 », « vt_shop_-80 »), que vtgate ne connaît
     * pas : les ouvrir échoue. Un keyspace qui commence seulement par « vt_ » n'est PAS reconnu comme tel.
     */
    public static function isVitessInternal(string $name): bool
    {
        return '_vt' === $name || 1 === preg_match('/^vt_.+_(?:0|[0-9a-f]*-[0-9a-f]*)$/iD', $name);
    }

    private const COMPRESSED = '~\s*/\*M?!\d+\s+COMPRESSED(?:=\w+)?\s*\*/~i';

    private const SYSTEM_DATABASES = ['information_schema', 'mysql', 'performance_schema', 'sys'];

    public function upkeep(): UpkeepReport
    {
        return (new MySqlOperations($this->connection))->upkeep();
    }

    public function orphanDefiners(): DefinerReport
    {
        return (new MySqlOperations($this->connection))->orphanDefiners();
    }

    public function listDatabases(): array
    {
        // information_schema.SCHEMATA ne montre que les bases sur lesquelles le compte a un droit. Sur vtgate elle est routée vers UN tablet :
        // elle rend les bases physiques de son mysqld (`_vt`, `vt_<keyspace>_0`) et cache les autres keyspaces ; `SHOW DATABASES` rend les
        // keyspaces (et les bases système). Une « base » Vitess est un keyspace.
        $names = $this->vitess
            ? array_filter($this->connection->fetchFirstColumn('SHOW DATABASES'), static fn (mixed $name): bool => !\is_string($name) || !self::isVitessInternal($name))
            : $this->connection->fetchFirstColumn('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA');
        $databases = [];
        foreach ($names as $name) {
            $name = (new RowReader(['name' => $name]))->string('name');
            $databases[] = new DatabaseSummary($name, \in_array(strtolower($name), self::SYSTEM_DATABASES, true));
        }
        // À l'octet : l'ordre du moteur dépend de la collation.
        usort($databases, static fn (DatabaseSummary $a, DatabaseSummary $b): int => strcmp($a->name, $b->name));

        return $databases;
    }

    public function listSchemas(): array
    {
        throw UnsupportedOperationException::for(Engine::MySql, 'schemas');
    }

    public function listTables(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT TABLE_NAME AS name, TABLE_TYPE AS kind, TABLE_ROWS AS estimate
               FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
              ORDER BY TABLE_NAME',
        );

        $tables = [];
        foreach ($rows as $row) {
            $row = new RowReader($row);
            $kind = self::kind($row->string('kind'));
            $tables[] = new TableSummary($row->string('name'), $kind, TableKind::View === $kind ? null : $row->nullableInt('estimate'));
        }
        // L'ordre du moteur dépend de la collation (MariaDB ne classe pas « _ » comme MySQL) : on trie à l'octet.
        usort($tables, static fn (TableSummary $a, TableSummary $b): int => strcmp($a->name, $b->name));

        return $tables;
    }

    private static function hasFourByteCharacter(string $text): bool
    {
        return 1 === preg_match('/[\x{10000}-\x{10FFFF}]/u', $text);
    }

    private static function count(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    public function listTablesPage(TablePageRequest $request): TablePage
    {
        // Aucun nom ne contient un caractère sur 4 octets (voir describeTable) : la comparaison avec TABLE_NAME échouerait, la réponse est vide.
        if (null !== $request->search && self::hasFourByteCharacter($request->search)) {
            return new TablePage([], null, null === $request->after ? 0 : null);
        }

        $where = 'TABLE_SCHEMA = DATABASE()';
        $params = [];
        if (null !== $request->search) {
            $where .= ' AND INSTR(LOWER(TABLE_NAME), LOWER(?)) > 0';
            $params[] = $request->search;
        }
        $total = null === $request->after
            ? self::count($this->connection->fetchOne('SELECT COUNT(*) FROM information_schema.TABLES WHERE '.$where, $params))
            : null;
        if (null !== $request->after) {
            // BINARY : on compare les octets, pas selon la collation (qui ignore la casse).
            $where .= ' AND BINARY TABLE_NAME > BINARY ?';
            $params[] = $request->after;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT TABLE_NAME AS name, TABLE_TYPE AS kind, TABLE_ROWS AS estimate
               FROM information_schema.TABLES
              WHERE '.$where.'
              ORDER BY BINARY TABLE_NAME
              LIMIT '.($request->limit + 1),
            $params,
        );

        return self::page($rows, $request->limit, $total);
    }

    /**
     * @param list<array<string, mixed>> $rows une ligne de plus que la limite : elle dit seulement qu'il y a une suite
     */
    private static function page(array $rows, int $limit, ?int $total): TablePage
    {
        $more = \count($rows) > $limit;
        $tables = [];
        foreach (\array_slice($rows, 0, $limit) as $row) {
            $row = new RowReader($row);
            $kind = self::kind($row->string('kind'));
            $tables[] = new TableSummary($row->string('name'), $kind, TableKind::View === $kind ? null : $row->nullableInt('estimate'));
        }

        return new TablePage($tables, $more ? $tables[\count($tables) - 1]->name : null, $total);
    }

    /**
     * Lectures par index depuis performance_schema (jamais sys, qui n'existe pas partout). La collecte part du démarrage du
     * serveur (ou d'une remise à zéro de la table) : la durée donnée est l'uptime, jamais un faux « depuis toujours ». Éteint
     * (MariaDB l'est par défaut), instrument coupé ou droit manquant : un état indisponible, pas une erreur.
     */
    public function indexUsage(): IndexUsageReport
    {
        try {
            $enabled = $this->connection->fetchOne('SELECT @@performance_schema');
            if (!\is_scalar($enabled) || 1 !== (int) $enabled) {
                return IndexUsageReport::unavailable('performance_schema_off');
            }
            $instrument = $this->connection->fetchOne("SELECT ENABLED FROM performance_schema.setup_instruments WHERE NAME = 'wait/io/table/sql/handler'");
            if ('YES' !== $instrument) {
                return IndexUsageReport::unavailable('instrument_off');
            }
            $uptime = $this->connection->fetchAssociative("SHOW GLOBAL STATUS LIKE 'Uptime'");
            $rows = $this->connection->fetchAllAssociative(
                'SELECT OBJECT_NAME AS tbl, INDEX_NAME AS idx, COUNT_READ AS read_count
                   FROM performance_schema.table_io_waits_summary_by_index_usage
                  WHERE OBJECT_SCHEMA = DATABASE() AND INDEX_NAME IS NOT NULL
                  ORDER BY OBJECT_NAME, INDEX_NAME',
            );
        } catch (DriverException $e) {
            return IndexUsageReport::unavailable(\in_array($e->getCode(), [1044, 1142, 1143, 1227], true) ? 'permission_denied' : 'unreadable');
        }

        $usages = [];
        foreach ($rows as $row) {
            $row = new RowReader($row);
            $usages[] = new IndexUsage($row->string('tbl'), $row->string('idx'), $row->int('read_count'));
        }

        return IndexUsageReport::available(false === $uptime ? 0 : (new RowReader($uptime))->int('Value'), $usages);
    }

    public function autoCounters(): array
    {
        // MySQL 8 garde AUTO_INCREMENT en cache jusqu'à 24 h (information_schema_stats_expiry) : on veut la valeur du moment.
        // MariaDB n'a pas cette variable et lit toujours à jour.
        try {
            $this->connection->executeStatement('SET SESSION information_schema_stats_expiry = 0');
        } catch (\Throwable) {
        }

        // AUTO_INCREMENT est la PROCHAINE valeur : la dernière attribuée en est le précédent (en décimal, un BIGINT UNSIGNED
        // dépasse l'entier de PHP). Une table sans ligne ni compteur n'a rien à dire.
        $rows = $this->connection->fetchAllAssociative(
            "SELECT t.TABLE_NAME AS tbl, c.COLUMN_NAME AS col, CAST(t.AUTO_INCREMENT AS DECIMAL(21, 0)) - 1 AS used
               FROM information_schema.TABLES t
               JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME
              WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE' AND t.AUTO_INCREMENT IS NOT NULL
                AND c.EXTRA LIKE '%auto_increment%'
              ORDER BY t.TABLE_NAME",
        );

        $counters = [];
        foreach ($rows as $row) {
            $row = new RowReader($row);
            $counters[] = new AutoCounter($row->string('tbl'), $row->string('col'), $row->string('used'));
        }

        return $counters;
    }

    public function describeTable(string $name): TableDefinition
    {
        // Les noms d'objets sont stockés en utf8mb3 : MariaDB refuse un caractère sur 4 octets, MySQL le range en « ? ». Un tel
        // nom ne désigne aucune table, et le comparer à une colonne utf8mb3 ferait échouer la requête (collations incompatibles).
        if (self::hasFourByteCharacter($name)) {
            throw TableNotFoundException::named($name);
        }

        $header = $this->connection->fetchAssociative(
            'SELECT t.TABLE_NAME AS name, t.TABLE_TYPE AS kind, t.TABLE_ROWS AS estimate, t.DATA_LENGTH AS data_bytes, t.INDEX_LENGTH AS index_bytes,
                    t.ENGINE AS storage_engine, e.TRANSACTIONS AS engine_transactions
               FROM information_schema.TABLES t
               LEFT JOIN information_schema.ENGINES e ON e.ENGINE = t.ENGINE
              WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = ?',
            [$name],
        );
        if (false === $header) {
            throw TableNotFoundException::named($name);
        }

        $header = new RowReader($header);
        // Le nom rendu par le moteur fait foi (sensibilité à la casse comprise).
        $name = $header->string('name');
        $kind = self::kind($header->string('kind'));

        return new TableDefinition(
            $name,
            $kind,
            $this->columns($name),
            $this->indexes($name),
            $this->foreignKeys($name),
            TableKind::View === $kind ? $this->viewDefinition($name) : null,
            TableKind::View === $kind ? null : $header->nullableInt('estimate'),
            TableKind::View === $kind ? null : $header->nullableInt('data_bytes'),
            TableKind::View === $kind ? null : $header->nullableInt('index_bytes'),
            TableKind::View === $kind ? null : $header->nullableString('storage_engine'),
            TableKind::View === $kind ? null : self::transactional($header->nullableString('engine_transactions')),
            TableKind::View === $kind ? null : $this->triggerCount($name),
            TableKind::View === $kind ? null : (new MySqlPartitionReader($this->connection))->read($name),
            versioned: 'SYSTEM VERSIONED' === $header->string('kind'),
        );
    }

    private static function transactional(?string $flag): ?bool
    {
        return null === $flag ? null : 'YES' === strtoupper($flag);
    }

    private function triggerCount(string $table): int
    {
        return self::count($this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?',
            [$table],
        ));
    }

    private static function kind(string $type): TableKind
    {
        return match ($type) {
            'VIEW' => TableKind::View,
            'SEQUENCE' => TableKind::Sequence,
            default => TableKind::Table,
        };
    }

    /**
     * @return list<ColumnDefinition>
     */
    private function columns(string $table): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS col_default,
                    EXTRA AS extra, COLUMN_COMMENT AS comment, GENERATION_EXPRESSION AS generation,
                    CHARACTER_SET_NAME AS charset, COLLATION_NAME AS collation
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
              ORDER BY ORDINAL_POSITION',
            [$table],
        );

        $checked = $this->columnsWithACheck($table);
        $columns = [];
        foreach ($rows as $row) {
            $row = new RowReader($row);
            $nullable = $row->bool('nullable');
            $default = $row->nullableString('col_default');
            // MariaDB écrit la compression en commentaire exécutable dans le type : on la lit à part.
            $type = $row->string('type');
            $compressed = 1 === preg_match(self::COMPRESSED, $type);
            $columns[] = new ColumnDefinition(
                $row->string('name'),
                $compressed ? trim((string) preg_replace(self::COMPRESSED, '', $type)) : $type,
                $nullable,
                // MariaDB écrit le littéral NULL pour « pas de défaut » sur une colonne nullable.
                $nullable && 'NULL' === $default ? null : $default,
                str_contains($row->string('extra'), 'auto_increment'),
                $row->nullableString('comment'),
                str_contains($row->string('extra'), 'INVISIBLE'),
                1 === preg_match('/on update\s+([A-Za-z_]+(?:\(\d*\))?)/i', $row->string('extra'), $onUpdate) ? $onUpdate[1] : null,
                $compressed,
                self::lock($row->string('extra'), (string) $row->nullableString('generation'), isset($checked[$row->string('name')])),
                $row->nullableString('charset'),
                $row->nullableString('collation'),
            );
        }

        return $columns;
    }

    /**
     * Pourquoi la colonne ne se redéfinit pas. MariaDB rend les colonnes de période d'une table versionnée comme des colonnes générées
     * dont l'expression est « ROW START » / « ROW END » : on les distingue d'une colonne générée ordinaire.
     */
    private static function lock(string $extra, string $generation, bool $checked): ?LockReason
    {
        if (1 === preg_match('/^ROW\s+(?:START|END)$/i', trim($generation))) {
            return LockReason::SystemPeriod;
        }
        if (1 === preg_match('/\b(?:VIRTUAL|STORED|PERSISTENT)\s+GENERATED\b/i', $extra) || '' !== $generation) {
            return LockReason::Generated;
        }

        return $checked ? LockReason::ColumnCheck : null;
    }

    /**
     * MariaDB : les colonnes qui portent une contrainte CHECK écrite sur la colonne (niveau « Column », nommée comme elle) ;
     * MODIFY COLUMN la ferait disparaître. MySQL la garde, et son catalogue n'a pas ce niveau : rien à verrouiller.
     *
     * @return array<string, true>
     */
    private function columnsWithACheck(string $table): array
    {
        $levels = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'information_schema' AND TABLE_NAME = 'CHECK_CONSTRAINTS' AND COLUMN_NAME = 'LEVEL'",
        );
        if (!is_numeric($levels) || (int) $levels < 1) {
            return [];
        }

        $names = $this->connection->fetchFirstColumn(
            "SELECT CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND LEVEL = 'Column'",
            [$table],
        );
        $checked = [];
        foreach ($names as $name) {
            $checked[(new RowReader(['name' => $name]))->string('name')] = true;
        }

        return $checked;
    }

    /**
     * La place de chaque index secondaire, d'après les statistiques persistantes d'InnoDB. Lecture facultative : sans droit sur
     * `mysql.innodb_index_stats` (ou avec un autre moteur de stockage), les tailles restent inconnues.
     *
     * @return array<string, int>
     */
    private function indexSizes(string $table): array
    {
        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT index_name AS name, stat_value * @@innodb_page_size AS size
                   FROM mysql.innodb_index_stats
                  WHERE database_name = DATABASE() AND table_name = ? AND stat_name = 'size'",
                [$table],
            );
        } catch (\Throwable) {
            return [];
        }

        $sizes = [];
        foreach ($rows as $row) {
            $row = new RowReader($row);
            $size = $row->nullableInt('size');
            if (null !== $size) {
                $sizes[$row->string('name')] = $size;
            }
        }

        return $sizes;
    }

    /**
     * Les colonnes de information_schema.STATISTICS selon la version : MySQL 8 a IS_VISIBLE et EXPRESSION, MariaDB a IGNORED.
     *
     * @return array<string, true>
     */
    private function statisticsColumns(): array
    {
        $names = $this->connection->fetchFirstColumn(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'information_schema' AND TABLE_NAME = 'STATISTICS'",
        );

        $available = [];
        foreach ($names as $name) {
            $available[strtoupper((new RowReader(['name' => $name]))->string('name'))] = true;
        }

        return $available;
    }

    /**
     * @return list<IndexDefinition>
     */
    private function indexes(string $table): array
    {
        $available = $this->statisticsColumns();
        $hidden = match (true) {
            isset($available['IS_VISIBLE']) => "IS_VISIBLE = 'NO'",
            isset($available['IGNORED']) => "IGNORED = 'YES'",
            default => '0',
        };
        $expression = isset($available['EXPRESSION']) ? 'EXPRESSION IS NOT NULL' : '0';
        $rows = $this->connection->fetchAllAssociative(
            'SELECT INDEX_NAME AS name, NON_UNIQUE AS non_unique, COLUMN_NAME AS col, ('.$hidden.') AS hidden,
                    COLLATION AS direction, SUB_PART AS prefix, INDEX_TYPE AS method, ('.$expression.') AS expression
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
              ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$table],
        );

        /** @var array<string, array{unique: bool, columns: list<string>, lock: ?LockReason}> $grouped */
        $grouped = [];
        foreach ($rows as $row) {
            $row = new RowReader($row);
            $name = $row->string('name');
            $grouped[$name] ??= ['unique' => !$row->bool('non_unique'), 'columns' => [], 'lock' => null];
            $column = $row->nullableString('col');
            if (null !== $column) {
                $grouped[$name]['columns'][] = $column;
            }
            // Ce que `CREATE INDEX (colonnes)` ne dit pas : recréer l'index sur ces seules colonnes le dégraderait.
            $grouped[$name]['lock'] ??= match (true) {
                $row->bool('hidden') => isset($available['IS_VISIBLE']) ? LockReason::IndexInvisible : LockReason::IndexIgnored,
                'FULLTEXT' === strtoupper($row->string('method')) => LockReason::Fulltext,
                \in_array(strtoupper($row->string('method')), ['SPATIAL', 'RTREE'], true) => LockReason::Spatial,
                $row->bool('expression') => LockReason::Expression,
                null !== $row->nullableString('prefix') => LockReason::Prefix,
                'D' === $row->nullableString('direction') => LockReason::Descending,
                default => null,
            };
        }

        $sizes = $this->indexSizes($table);
        $indexes = [];
        foreach ($grouped as $name => $index) {
            if ([] === $index['columns']) {
                continue;
            }
            // La clé primaire InnoDB est la table elle-même : sa place est déjà dans les données.
            $indexes[] = new IndexDefinition((string) $name, $index['columns'], $index['unique'], 'PRIMARY' === $name, 'PRIMARY' === $name ? null : ($sizes[(string) $name] ?? null), $index['lock']);
        }
        usort($indexes, static fn (IndexDefinition $a, IndexDefinition $b): int => [!$a->primary, 0] <=> [!$b->primary, 0] ?: strcmp($a->name, $b->name));

        return $indexes;
    }

    /**
     * @return list<ForeignKeyDefinition>
     */
    private function foreignKeys(string $table): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT k.CONSTRAINT_NAME AS name, k.COLUMN_NAME AS col, k.REFERENCED_TABLE_NAME AS ref_table,
                    k.REFERENCED_COLUMN_NAME AS ref_col, r.UPDATE_RULE AS on_update, r.DELETE_RULE AS on_delete
               FROM information_schema.KEY_COLUMN_USAGE k
               JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                 ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
                AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
                AND r.TABLE_NAME = k.TABLE_NAME
              WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL
              ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION',
            [$table],
        );

        /** @var array<string, array{table: string, update: string, delete: string, columns: list<string>, referenced: list<string>}> $grouped */
        $grouped = [];
        foreach ($rows as $row) {
            $row = new RowReader($row);
            $name = $row->string('name');
            $grouped[$name] ??= [
                'table' => $row->string('ref_table'),
                'update' => $row->string('on_update'),
                'delete' => $row->string('on_delete'),
                'columns' => [],
                'referenced' => [],
            ];
            $grouped[$name]['columns'][] = $row->string('col');
            $grouped[$name]['referenced'][] = $row->string('ref_col');
        }

        $keys = [];
        foreach ($grouped as $name => $key) {
            $keys[] = new ForeignKeyDefinition(
                (string) $name,
                $key['columns'],
                $key['table'],
                $key['referenced'],
                ReferentialAction::fromSql($key['update']),
                ReferentialAction::fromSql($key['delete']),
            );
        }
        usort($keys, static fn (ForeignKeyDefinition $a, ForeignKeyDefinition $b): int => strcmp($a->name, $b->name));

        return $keys;
    }

    private function viewDefinition(string $view): ?string
    {
        $definition = $this->connection->fetchOne(
            'SELECT VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$view],
        );

        return \is_string($definition) && '' !== $definition ? $definition : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Guard;

use App\Core\Connection\Engine;
use App\Core\Query\QueryMode;

/**
 * Premier rempart avant d'envoyer une instruction à une cible : une instruction à la fois, classée par son
 * premier mot, avec des refus nets (sortie de la transaction, accès aux fichiers du serveur) et des demandes
 * de confirmation pour ce qui détruit (UPDATE ou DELETE sans WHERE, DROP, TRUNCATE…).
 *
 * C'est un filtre lexical, donc contournable par nature : il ne remplace pas la transaction en lecture seule
 * du moteur, qui fait foi (voir QueryRunner). Il sert à refuser tôt, avec un motif lisible.
 */
final class StatementGuard
{
    private const READ = ['SELECT', 'VALUES', 'TABLE'];
    private const INSPECT = ['SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'EXISTS'];
    private const WRITE = ['INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'MERGE', 'CALL', 'DO'];
    private const SCHEMA_SAFE = ['CREATE', 'COMMENT', 'ANALYZE', 'OPTIMIZE', 'VACUUM', 'REINDEX', 'REFRESH', 'CLUSTER', 'ATTACH'];
    private const SCHEMA_DESTRUCTIVE = ['DROP', 'TRUNCATE', 'ALTER', 'RENAME', 'GRANT', 'REVOKE', 'DETACH', 'EXCHANGE'];
    private const WRITE_VERBS = ['INSERT', 'UPDATE', 'DELETE', 'MERGE', 'REPLACE'];
    // Lecture ou écriture de fichiers du serveur, contrôle du serveur, ouverture de connexions sortantes.
    private const DANGEROUS_FUNCTIONS = [
        'LOAD_FILE', 'PG_READ_FILE', 'PG_READ_BINARY_FILE', 'PG_LS_DIR', 'PG_STAT_FILE', 'LO_IMPORT', 'LO_EXPORT',
        'PG_TERMINATE_BACKEND', 'PG_CANCEL_BACKEND', 'PG_RELOAD_CONF', 'PG_ROTATE_LOGFILE', 'SET_CONFIG',
    ];
    // Fonctions de table ClickHouse qui lisent des fichiers du serveur ou ouvrent des connexions sortantes : elles ne
    // sont refusées que lorsqu'elles sont appelées (« file(…) »), une colonne nommée « file » reste lisible.
    private const CLICKHOUSE_TABLE_FUNCTIONS = [
        'FILE', 'FILECLUSTER', 'URL', 'URLCLUSTER', 'S3', 'S3CLUSTER', 'GCS', 'HDFS', 'HDFSCLUSTER', 'REMOTE', 'REMOTESECURE',
        'CLUSTER', 'CLUSTERALLREPLICAS', 'MYSQL', 'POSTGRESQL', 'MONGODB', 'REDIS', 'SQLITE', 'ODBC', 'JDBC', 'EXECUTABLE',
        'INPUT', 'AZUREBLOBSTORAGE', 'AZUREBLOBSTORAGECLUSTER', 'DELTALAKE', 'ICEBERG', 'HUDI', 'MERGE', 'YTSAURUS',
        'ARROWFLIGHT', 'NATS', 'KAFKA', 'RABBITMQ',
    ];
    private const LOCKING_CLAUSES = [
        ['FOR', 'UPDATE'], ['FOR', 'SHARE'], ['FOR', 'NO', 'KEY', 'UPDATE'], ['FOR', 'KEY', 'SHARE'], ['LOCK', 'IN', 'SHARE', 'MODE'],
    ];

    private const MAX_LENGTH_BYTES = 100_000;

    private readonly SqlScanner $scanner;
    private readonly RedisCommandGuard $redis;

    public function __construct(?SqlScanner $scanner = null, ?RedisCommandGuard $redis = null)
    {
        $this->scanner = $scanner ?? new SqlScanner();
        $this->redis = $redis ?? new RedisCommandGuard();
    }

    public function check(string $sql, Engine $engine, QueryMode $mode): GuardVerdict
    {
        if (!$engine->speaksSql()) {
            return $this->redis->check($sql, $mode);
        }

        if (\strlen($sql) > self::MAX_LENGTH_BYTES) {
            return GuardVerdict::refused(GuardReason::TooLong);
        }

        $scan = $this->scanner->scan($sql, SqlDialect::forEngine($engine));
        if (!$scan->complete) {
            return GuardVerdict::refused(GuardReason::IncompleteSql);
        }
        if ([] === $scan->statements) {
            return GuardVerdict::refused(GuardReason::EmptyStatement);
        }
        if (\count($scan->statements) > 1) {
            return GuardVerdict::refused(GuardReason::MultipleStatements);
        }

        $statement = $scan->statements[0];
        $first = $statement->first();

        if (\in_array($first, ['LOAD', 'COPY'], true) || $statement->hasSequence('INTO', 'OUTFILE') || $statement->hasSequence('INTO', 'DUMPFILE')) {
            return GuardVerdict::refused(GuardReason::ForbiddenConstruct);
        }

        $kind = $this->kind($statement);
        if (null === $kind) {
            return GuardVerdict::refused(GuardReason::UnsupportedStatement);
        }
        if (Engine::ClickHouse === $engine && $this->usesClickHouseOutsideAccess($statement)) {
            return GuardVerdict::refused(GuardReason::ForbiddenConstruct, $kind);
        }

        return QueryMode::ReadOnly === $mode
            ? $this->checkReadOnly($statement, $kind)
            : $this->checkReadWrite($statement, $kind);
    }

    private function kind(ScannedStatement $statement): ?StatementKind
    {
        $first = $statement->first();

        return match (true) {
            'WITH' === $first => $this->hasWriteVerb($statement) ? StatementKind::Write : StatementKind::Read,
            'EXPLAIN' === $first => ($statement->has('ANALYZE') || $statement->has('ANALYSE')) && $this->hasWriteVerb($statement) ? StatementKind::Write : StatementKind::Inspect,
            \in_array($first, self::READ, true) => StatementKind::Read,
            \in_array($first, self::INSPECT, true) => StatementKind::Inspect,
            \in_array($first, self::WRITE, true) => StatementKind::Write,
            \in_array($first, self::SCHEMA_SAFE, true), \in_array($first, self::SCHEMA_DESTRUCTIVE, true) => StatementKind::Schema,
            default => null,
        };
    }

    /**
     * Fonctions de table qui sortent du serveur, ou clause FORMAT (le runner impose son propre format de sortie).
     */
    private function usesClickHouseOutsideAccess(ScannedStatement $statement): bool
    {
        foreach (self::CLICKHOUSE_TABLE_FUNCTIONS as $function) {
            if ($statement->calls($function)) {
                return true;
            }
        }

        return $statement->hasTopLevel('FORMAT') && !$statement->calls('FORMAT');
    }

    private function hasWriteVerb(ScannedStatement $statement): bool
    {
        foreach (self::WRITE_VERBS as $verb) {
            if ($statement->has($verb)) {
                return true;
            }
        }

        return false;
    }

    private function checkReadOnly(ScannedStatement $statement, StatementKind $kind): GuardVerdict
    {
        if (StatementKind::Read !== $kind && StatementKind::Inspect !== $kind) {
            return GuardVerdict::refused(GuardReason::NotReadOnly, $kind);
        }
        if ($statement->has('INTO') || $this->hasLockingClause($statement) || $this->usesDangerousFunction($statement)) {
            return GuardVerdict::refused(GuardReason::ForbiddenConstruct, $kind);
        }

        return GuardVerdict::allowed($kind);
    }

    private function checkReadWrite(ScannedStatement $statement, StatementKind $kind): GuardVerdict
    {
        $first = $statement->first();

        if (\in_array($first, ['CALL', 'DO'], true) || \in_array($first, self::SCHEMA_DESTRUCTIVE, true)) {
            return GuardVerdict::needsConfirmation($kind, GuardReason::DestructiveStatement);
        }
        if (StatementKind::Write === $kind && $this->modifiesRowsWithoutWhere($statement)) {
            return GuardVerdict::needsConfirmation($kind, GuardReason::DestructiveWithoutWhere);
        }

        return GuardVerdict::allowed($kind);
    }

    /**
     * Un UPDATE ou un DELETE (éventuellement derrière WITH ou EXPLAIN ANALYZE) sans WHERE de même niveau :
     * le WHERE d'une sous-requête ne limite pas les lignes touchées.
     */
    private function modifiesRowsWithoutWhere(ScannedStatement $statement): bool
    {
        $first = $statement->first();
        $modifies = \in_array($first, ['UPDATE', 'DELETE'], true)
            || (\in_array($first, ['WITH', 'EXPLAIN'], true) && ($statement->hasTopLevel('UPDATE') || $statement->hasTopLevel('DELETE')));

        return $modifies && !$statement->hasTopLevel('WHERE');
    }

    private function hasLockingClause(ScannedStatement $statement): bool
    {
        foreach (self::LOCKING_CLAUSES as $clause) {
            if ($statement->hasSequence(...$clause)) {
                return true;
            }
        }

        return false;
    }

    private function usesDangerousFunction(ScannedStatement $statement): bool
    {
        foreach ($statement->words as $word) {
            if (\in_array($word, self::DANGEROUS_FUNCTIONS, true) || str_starts_with($word, 'DBLINK')) {
                return true;
            }
        }

        return false;
    }
}

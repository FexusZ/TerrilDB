<?php

declare(strict_types=1);

namespace App\Core\Guard;

use App\Core\Query\QueryMode;

/**
 * Le garde des commandes Redis. Contrairement au SQL, on ne cherche pas ce qui est interdit : seule une liste blanche
 * de commandes connues passe, en lecture ou en écriture. Redis n'a pas de transaction en lecture seule côté client ;
 * la liste blanche (revérifiée par le serveur au moment d'exécuter, voir RedisQueryRunner) tient ce rôle.
 *
 * Refus nets : commandes bloquantes ou de flux (MONITOR, SUBSCRIBE, BLPOP, XREAD : le serveur classe XREAD comme bloquant), KEYS (SCAN le remplace sans
 * bloquer le serveur), scripts et modules (ils contourneraient le filtre), administration (CONFIG, ACL, CLIENT, DEBUG,
 * SHUTDOWN, réplication, sauvegardes), AUTH, FLUSHALL (hors de la base de la connexion). FLUSHDB demande confirmation.
 */
final class RedisCommandGuard
{
    private const MAX_LENGTH_BYTES = 100_000;

    private const READ = [
        'GET', 'MGET', 'STRLEN', 'GETRANGE', 'SUBSTR', 'LCS', 'EXISTS', 'TYPE', 'TTL', 'PTTL', 'EXPIRETIME', 'PEXPIRETIME',
        'DBSIZE', 'SCAN', 'RANDOMKEY', 'HGET', 'HMGET', 'HGETALL', 'HKEYS', 'HVALS', 'HLEN', 'HEXISTS', 'HSTRLEN', 'HSCAN',
        'HRANDFIELD', 'LRANGE', 'LLEN', 'LINDEX', 'LPOS', 'SMEMBERS', 'SCARD', 'SISMEMBER', 'SMISMEMBER', 'SSCAN', 'SRANDMEMBER',
        'SINTER', 'SUNION', 'SDIFF', 'SINTERCARD', 'ZRANGE', 'ZRANGEBYSCORE', 'ZRANGEBYLEX', 'ZREVRANGE', 'ZREVRANGEBYSCORE',
        'ZREVRANGEBYLEX', 'ZCARD', 'ZCOUNT', 'ZLEXCOUNT', 'ZSCORE', 'ZMSCORE', 'ZRANK', 'ZREVRANK', 'ZSCAN', 'ZRANDMEMBER',
        'ZINTER', 'ZUNION', 'ZDIFF', 'XRANGE', 'XREVRANGE', 'XLEN', 'XPENDING', 'GETBIT', 'BITCOUNT', 'BITPOS',
        'GEOPOS', 'GEODIST', 'GEOHASH', 'GEOSEARCH', 'PING', 'ECHO', 'INFO', 'TIME', 'SORT_RO',
    ];

    /** Commandes à sous-commande : seules ces sous-commandes passent (en lecture). */
    private const READ_SUBCOMMANDS = [
        'OBJECT' => ['ENCODING', 'FREQ', 'IDLETIME', 'REFCOUNT'],
        'MEMORY' => ['USAGE'],
        'XINFO' => ['STREAM', 'GROUPS', 'CONSUMERS'],
        'COMMAND' => ['COUNT', 'INFO', 'DOCS'],
    ];

    private const WRITE = [
        'SET', 'MSET', 'MSETNX', 'SETEX', 'PSETEX', 'SETNX', 'APPEND', 'INCR', 'INCRBY', 'INCRBYFLOAT', 'DECR', 'DECRBY', 'GETSET',
        'GETDEL', 'GETEX', 'DEL', 'UNLINK', 'EXPIRE', 'PEXPIRE', 'EXPIREAT', 'PEXPIREAT', 'PERSIST', 'RENAME', 'RENAMENX', 'COPY',
        'HSET', 'HSETNX', 'HMSET', 'HDEL', 'HINCRBY', 'HINCRBYFLOAT', 'LPUSH', 'RPUSH', 'LPUSHX', 'RPUSHX', 'LPOP', 'RPOP', 'LSET',
        'LREM', 'LTRIM', 'LINSERT', 'LMOVE', 'SADD', 'SREM', 'SPOP', 'SMOVE', 'ZADD', 'ZREM', 'ZINCRBY', 'ZPOPMIN', 'ZPOPMAX',
        'ZREMRANGEBYSCORE', 'ZREMRANGEBYRANK', 'ZREMRANGEBYLEX', 'XADD', 'XDEL', 'XTRIM', 'PFADD', 'SETBIT', 'SETRANGE', 'GEOADD',
        'FLUSHDB',
    ];

    // Refus avec un motif « interdit » : dangereux, bloquant ou hors de la base de la connexion.
    private const FORBIDDEN = [
        'KEYS', 'MONITOR', 'SUBSCRIBE', 'PSUBSCRIBE', 'SSUBSCRIBE', 'UNSUBSCRIBE', 'PUNSUBSCRIBE', 'PUBLISH', 'SPUBLISH', 'BLPOP', 'BRPOP',
        'BRPOPLPUSH', 'BLMOVE', 'BLMPOP', 'BZPOPMIN', 'BZPOPMAX', 'BZMPOP', 'XREAD', 'XREADGROUP', 'DEBUG', 'EVAL', 'EVALSHA', 'EVAL_RO',
        'EVALSHA_RO', 'FCALL', 'FCALL_RO', 'FUNCTION', 'SCRIPT', 'MODULE', 'CONFIG', 'ACL', 'CLIENT', 'SHUTDOWN', 'REPLICAOF', 'SLAVEOF',
        'MIGRATE', 'SAVE', 'BGSAVE', 'BGREWRITEAOF', 'FLUSHALL', 'AUTH', 'HELLO', 'FAILOVER', 'CLUSTER', 'READONLY', 'READWRITE', 'SLOWLOG',
        'LATENCY', 'PSYNC', 'SYNC', 'WAIT', 'WAITAOF', 'LOLWUT', 'RESTORE', 'RESTORE-ASKING', 'SWAPDB',
    ];

    // Contrôle de session ou de transaction : la connexion est fixée, il n'y a pas de session côté TerrilDB.
    private const SESSION = ['SELECT', 'MULTI', 'EXEC', 'DISCARD', 'WATCH', 'UNWATCH', 'QUIT', 'RESET', 'MOVE'];

    /**
     * Les commandes de lecture que le garde laisse passer, sous la forme où le serveur les connaît (« OBJECT|ENCODING »
     * pour une sous-commande) : sert à vérifier que le serveur ne les classe pas parmi les écritures ou l'administration.
     *
     * @return list<string>
     */
    public static function readCommandNames(): array
    {
        $names = self::READ;
        foreach (self::READ_SUBCOMMANDS as $command => $subcommands) {
            foreach ($subcommands as $subcommand) {
                $names[] = $command.'|'.$subcommand;
            }
        }

        return $names;
    }

    public function check(string $text, QueryMode $mode): GuardVerdict
    {
        if (\strlen($text) > self::MAX_LENGTH_BYTES) {
            return GuardVerdict::refused(GuardReason::TooLong);
        }

        $line = RedisCommandLine::parse($text);
        if (!$line->complete) {
            return GuardVerdict::refused(GuardReason::IncompleteSql);
        }
        if ([] === $line->commands) {
            return GuardVerdict::refused(GuardReason::EmptyStatement);
        }
        if (\count($line->commands) > 1) {
            return GuardVerdict::refused(GuardReason::MultipleStatements);
        }

        $arguments = $line->commands[0];
        $name = strtoupper($arguments[0] ?? '');

        if (\in_array($name, self::FORBIDDEN, true)) {
            return GuardVerdict::refused(GuardReason::ForbiddenConstruct);
        }
        if (\in_array($name, self::SESSION, true)) {
            return GuardVerdict::refused(GuardReason::UnsupportedStatement);
        }
        if (isset(self::READ_SUBCOMMANDS[$name])) {
            $sub = isset($arguments[1]) ? strtoupper($arguments[1]) : null;
            if (null === $sub) {
                return GuardVerdict::refused(GuardReason::UnsupportedStatement);
            }

            return \in_array($sub, self::READ_SUBCOMMANDS[$name], true)
                ? GuardVerdict::allowed(StatementKind::Read)
                : GuardVerdict::refused(GuardReason::ForbiddenConstruct);
        }
        if (\in_array($name, self::READ, true)) {
            return GuardVerdict::allowed(StatementKind::Read);
        }
        if (\in_array($name, self::WRITE, true)) {
            if (QueryMode::ReadOnly === $mode) {
                return GuardVerdict::refused(GuardReason::NotReadOnly, StatementKind::Write);
            }

            return 'FLUSHDB' === $name
                ? GuardVerdict::needsConfirmation(StatementKind::Write, GuardReason::DestructiveStatement)
                : GuardVerdict::allowed(StatementKind::Write);
        }

        return GuardVerdict::refused(GuardReason::UnsupportedStatement);
    }
}

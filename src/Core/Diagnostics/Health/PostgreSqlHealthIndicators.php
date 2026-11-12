<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Health;

/**
 * Indicateurs de santé de PostgreSQL d'après deux relevés de `pg_stat_database` (base de la connexion, calcul pur). Repères à
 * valider sur une charge représentative :
 *
 * - connexions / `max_connections` : 80 % ; annulations / transactions : 10 %, si l'intervalle compte au moins 100 transactions ;
 * - blocs servis par le cache partagé : sous 99 %, si l'intervalle compte au moins 1000 blocs ;
 * - fichiers temporaires : 1 par seconde ; interblocages : au moins 1.
 */
final class PostgreSqlHealthIndicators
{
    /**
     * @return list<HealthIndicator>
     */
    public static function compute(HealthWindow $window): array
    {
        $commits = $window->delta('xact_commit');
        $rollbacks = $window->delta('xact_rollback');
        $transactions = null === $commits || null === $rollbacks ? null : $commits + $rollbacks;
        $hits = $window->delta('blks_hit');
        $reads = $window->delta('blks_read');
        $blocks = null === $hits || null === $reads ? null : $hits + $reads;
        $seconds = $window->seconds();

        return [
            Indicators::rate('transactions_per_second', null === $transactions || $seconds <= 0.0 ? null : $transactions / $seconds),
            Indicators::percent('rollback_percent', $rollbacks, $transactions, 10.0, 100.0),
            Indicators::percent('cache_hit_percent', $hits, $blocks, 99.0, 1000.0, false),
            Indicators::percent('connections_used_percent', $window->gauge('connections'), $window->gauge('max_connections'), 80.0),
            Indicators::rate('temp_files_per_second', $window->rate('temp_files'), 1.0),
            Indicators::count('deadlocks', $window->delta('deadlocks'), 1.0),
            Indicators::rate('rows_returned_per_second', $window->rate('tup_returned')),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Health;

/**
 * Indicateurs de santé de MySQL et MariaDB d'après deux relevés de `SHOW GLOBAL STATUS` (calcul pur). Les seuils sont des
 * REPÈRES à valider sur une charge représentative, pas des optimums :
 *
 * - connexions courantes / `max_connections` : avertissement dès 80 % ;
 * - fils en exécution : 50 ;
 * - connexions avortées / tentatives de connexion : 5 %, si l'intervalle compte au moins 10 tentatives ;
 * - lectures servies par le buffer pool : sous 99 % des demandes, si l'intervalle compte au moins 1000 demandes ;
 * - tables temporaires créées sur disque : 25 %, si l'intervalle en compte au moins 100 ;
 * - attentes de verrous de ligne : avertissement si l'attente MOYENNE de l'intervalle atteint 1000 ms ;
 * - requêtes lentes : 1 par seconde ; attentes d'espace dans le journal de reprise (redo) : au moins 1.
 *
 * Un compteur absent de cette série (noms qui varient entre versions et entre MySQL et MariaDB) ou remis à zéro donne
 * l'indicateur « inconnu », jamais zéro.
 */
final class MySqlHealthIndicators
{
    /**
     * @return list<HealthIndicator>
     */
    public static function compute(HealthWindow $window): array
    {
        $waits = $window->delta('Innodb_row_lock_waits');
        $lockTime = $window->delta('Innodb_row_lock_time');
        $lockRate = Indicators::rate('row_lock_waits_per_second', $window->rate('Innodb_row_lock_waits'));
        if (null !== $waits && $waits >= 1.0 && null !== $lockTime && $lockTime / $waits >= 1000.0) {
            $lockRate = new HealthIndicator($lockRate->key, $lockRate->value, $lockRate->unit, HealthLevel::Warning, null);
        }

        return [
            Indicators::rate('queries_per_second', $window->rate('Questions')),
            Indicators::percent('connections_used_percent', $window->gauge('Threads_connected'), $window->gauge('max_connections'), 80.0),
            Indicators::count('threads_running', $window->gauge('Threads_running'), 50.0),
            Indicators::percent('aborted_connects_percent', $window->delta('Aborted_connects'), $window->delta('Connections'), 5.0, 10.0),
            Indicators::percent('buffer_pool_hit_percent', $window->delta('Innodb_buffer_pool_reads'), $window->delta('Innodb_buffer_pool_read_requests'), 99.0, 1000.0, false, true),
            Indicators::percent('tmp_disk_tables_percent', $window->delta('Created_tmp_disk_tables'), $window->delta('Created_tmp_tables'), 25.0, 100.0),
            $lockRate,
            Indicators::rate('slow_queries_per_second', $window->rate('Slow_queries'), 1.0),
            Indicators::count('redo_log_waits', $window->delta('Innodb_log_waits'), 1.0),
        ];
    }
}

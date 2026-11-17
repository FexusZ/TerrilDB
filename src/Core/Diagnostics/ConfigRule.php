<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

use App\Core\Connection\Engine;

/**
 * Les règles de l'audit de configuration : chacune ne regarde que des variables lues, sans jamais rien exécuter. L'explication est
 * un texte fixe (jamais une donnée du serveur), à lire avant de changer quoi que ce soit.
 */
enum ConfigRule: string
{
    case SqlModeNotStrict = 'sql_mode_not_strict';
    case LocalInfileEnabled = 'local_infile_enabled';
    case SkipNameResolveMissing = 'skip_name_resolve_missing';
    case BinlogNeverExpires = 'binlog_never_expires';
    case FlushLogAtCommitRelaxed = 'flush_log_at_commit_relaxed';
    case SyncBinlogRelaxed = 'sync_binlog_relaxed';
    case PerformanceSchemaOff = 'performance_schema_off';
    case SlowQueryLogOff = 'slow_query_log_off';
    case GeneralLogOn = 'general_log_on';
    case MaxAllowedPacketTiny = 'max_allowed_packet_tiny';
    case TmpTableAboveHeapTable = 'tmp_table_above_heap_table';
    case SecureFilePrivEmpty = 'secure_file_priv_empty';
    case DoublewriteOff = 'doublewrite_off';
    case FsyncOff = 'fsync_off';
    case FullPageWritesOff = 'full_page_writes_off';
    case SynchronousCommitOff = 'synchronous_commit_off';
    case SlowStatementLogOff = 'slow_statement_log_off';
    case SslOff = 'ssl_off';
    case SslMinProtocolOld = 'ssl_min_protocol_old';
    case AutovacuumOff = 'autovacuum_off';
    case TrackCountsOff = 'track_counts_off';
    case ZeroDamagedPagesOn = 'zero_damaged_pages_on';
    case IgnoreChecksumFailureOn = 'ignore_checksum_failure_on';
    case DataChecksumsOff = 'data_checksums_off';

    /**
     * @return list<self>
     */
    public static function forEngine(Engine $engine): array
    {
        return match ($engine) {
            Engine::MySql => array_values(array_filter(self::cases(), static fn (self $r): bool => $r->isRelational() && self::TmpTableAboveHeapTable !== $r)),
            Engine::MariaDb => array_values(array_filter(self::cases(), static fn (self $r): bool => $r->isRelational())),
            Engine::PostgreSql => array_values(array_filter(self::cases(), static fn (self $r): bool => !$r->isRelational())),
            default => [],
        };
    }

    /** Vrai pour les règles de MySQL et de MariaDB, faux pour celles de PostgreSQL. */
    private function isRelational(): bool
    {
        return \in_array($this, [
            self::SqlModeNotStrict, self::LocalInfileEnabled, self::SkipNameResolveMissing, self::BinlogNeverExpires, self::FlushLogAtCommitRelaxed,
            self::SyncBinlogRelaxed, self::PerformanceSchemaOff, self::SlowQueryLogOff, self::GeneralLogOn, self::MaxAllowedPacketTiny,
            self::TmpTableAboveHeapTable, self::SecureFilePrivEmpty, self::DoublewriteOff,
        ], true);
    }

    public function explanation(): string
    {
        return match ($this) {
            self::SqlModeNotStrict => 'Sans mode strict, le serveur tronque ou convertit en silence une valeur hors limites au lieu de refuser l\'écriture.',
            self::LocalInfileEnabled => 'LOAD DATA LOCAL permet à un serveur compromis de demander des fichiers au client ; il est désactivé par défaut sur MySQL.',
            self::SkipNameResolveMissing => 'Chaque connexion déclenche une résolution DNS inverse du client, source de lenteurs et de pannes. Attention : les comptes définis par nom d\'hôte cesseraient de fonctionner.',
            self::BinlogNeverExpires => 'Le journal binaire est activé mais jamais purgé : il grossit jusqu\'à remplir le disque.',
            self::FlushLogAtCommitRelaxed => 'Avec une valeur autre que 1, une panne peut faire perdre les dernières transactions validées (jusqu\'à une seconde).',
            self::SyncBinlogRelaxed => 'Avec une valeur autre que 1, une panne peut perdre des transactions validées du journal binaire (réplication, restauration à un instant).',
            self::PerformanceSchemaOff => 'Sans performance_schema, les sessions, verrous et requêtes coûteuses ne sont pas observables (redémarrage nécessaire pour l\'activer).',
            self::SlowQueryLogOff => 'Sans journal des requêtes lentes, rien ne garde la trace des requêtes qui dépassent le seuil.',
            self::GeneralLogOn => 'Le journal général enregistre TOUTES les instructions, valeurs et mots de passe de certaines commandes compris, et ralentit le serveur.',
            self::MaxAllowedPacketTiny => 'Un paquet maximal sous 1 Mio refuse des lignes ou des requêtes ordinaires.',
            self::TmpTableAboveHeapTable => 'Les tables temporaires en mémoire sont limitées par la PLUS PETITE des deux valeurs : tmp_table_size n\'a aucun effet au-delà de max_heap_table_size.',
            self::SecureFilePrivEmpty => 'Vide, secure_file_priv ne limite plus les répertoires où le serveur lit et écrit des fichiers (LOAD DATA, SELECT … INTO OUTFILE, LOAD_FILE).',
            self::DoublewriteOff => 'Sans double écriture, une panne en pleine écriture d\'une page peut la corrompre sans retour possible.',
            self::FsyncOff => 'Sans fsync, une panne ou une coupure de courant peut corrompre la base de façon irrécupérable.',
            self::FullPageWritesOff => 'Sans pages complètes dans le journal, une panne peut laisser des pages à moitié écrites et corrompre la base.',
            self::SynchronousCommitOff => 'Une validation est rendue avant son écriture sur disque : une panne perd les dernières transactions (sans corrompre la base).',
            self::SlowStatementLogOff => 'Le journal des instructions lentes est éteint (-1) : aucune trace des requêtes qui dépassent un seuil.',
            self::SslOff => 'Les connexions entrantes ne peuvent pas être chiffrées par TLS : mots de passe et données circulent en clair sur le réseau.',
            self::SslMinProtocolOld => 'Le plancher TLS accepte TLS 1.0 ou 1.1, abandonnés et jugés faibles.',
            self::AutovacuumOff => 'Sans autovacuum, les lignes mortes s\'accumulent, les tables gonflent et le compteur de transactions finit par bloquer les écritures.',
            self::TrackCountsOff => 'L\'autovacuum s\'appuie sur les statistiques d\'activité : sans elles, il ne sait plus quelles tables nettoyer.',
            self::ZeroDamagedPagesOn => 'Une page endommagée est remplacée par des zéros au lieu d\'arrêter la lecture : les données qu\'elle portait sont perdues. Réservé à la récupération après sinistre.',
            self::IgnoreChecksumFailureOn => 'Une somme de contrôle fausse est ignorée : une page corrompue est lue comme si de rien n\'était. Réservé à la récupération après sinistre.',
            self::DataChecksumsOff => 'Sans sommes de contrôle, une corruption silencieuse des pages n\'est pas détectée (activables avec pg_checksums, serveur arrêté).',
        };
    }
}

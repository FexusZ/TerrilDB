import type { DiagnosticStatus, ReplicationAlert, ReplicationChannel, ReplicationReport, ReplicationRole, SemiSyncMode, ThreadState } from '../api/types'
import { describeStatus, formatSeconds } from './diagnostics'

export function roleLabel(role: ReplicationRole): string {
  switch (role) {
    case 'none':
      return 'Pas de réplication configurée'
    case 'source':
      return 'Source'
    case 'replica':
      return 'Réplica'
    case 'peer':
      return 'Tables répliquées entre pairs'
  }
}

export function threadLabel(state: ThreadState): string {
  switch (state) {
    case 'running':
      return 'actif'
    case 'connecting':
      return 'connexion en cours'
    case 'stopped':
      return 'arrêté'
  }
}

const BYTES = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 })
const BYTES_SMALL = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 })

/** Une taille en octets dans la plus grande unité binaire raisonnable (o, Kio, Mio, Gio, Tio). */
export function formatBytes(bytes: number | null): string {
  if (bytes === null) return 'inconnu'
  const units = ['o', 'Kio', 'Mio', 'Gio', 'Tio']
  let value = Math.max(0, bytes)
  let unit = 0
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024
    unit++
  }

  return `${(value < 10 && unit > 0 ? BYTES_SMALL : BYTES).format(value)} ${units[unit] ?? 'o'}`
}

/** Ce que dit l'état du WAL d'un slot (`wal_status`) ; une valeur inconnue n'est jamais affichée telle quelle. */
export function walStatusLabel(status: string | null): string {
  switch (status) {
    case null:
      return 'rien de retenu'
    case 'reserved':
      return 'WAL retenu dans les limites normales'
    case 'extended':
      return 'WAL retenu au-delà de max_wal_size, encore protégé'
    case 'unreserved':
      return 'WAL menacé : il sera supprimé au prochain point de reprise'
    case 'lost':
      return 'WAL perdu : le slot ne peut plus servir'
    default:
      return 'état du WAL inconnu de cette version'
  }
}

export function semiSyncLabel(mode: SemiSyncMode): string {
  switch (mode) {
    case 'not_loaded':
      return 'greffon non chargé'
    case 'off':
      return 'désactivé'
    case 'active':
      return 'actif'
    case 'fallen_back':
      return 'activé mais repassé en asynchrone'
  }
}

const SECTION_REASONS: Record<string, string> = {
  binary_log_off: 'Le journal binaire est éteint sur ce serveur : rien n’y est enregistré pour les réplicas.',
  semi_sync_not_loaded: 'Le greffon semi-synchrone n’est pas chargé : la réplication y est asynchrone.',
}

/** Pourquoi une section du moteur ne se lit pas, ou null ; un état attendu (journal éteint, greffon absent) n'est pas présenté comme une panne. */
export function sectionNote(status: DiagnosticStatus, topic: string): string | null {
  if (status.availability === 'disabled' && status.reason !== null && SECTION_REASONS[status.reason] !== undefined) return SECTION_REASONS[status.reason] ?? null

  return describeStatus(status, topic)
}

/** Le sujet d'une alerte : le canal, le slot, le réplica ou la table, selon la famille du code. */
function subject(alert: ReplicationAlert): string {
  if (alert.channel === '') return ''
  if (alert.code.startsWith('slot_')) return ` (slot ${alert.channel})`
  if (alert.code.startsWith('replica_')) return ` (réplica ${alert.channel})`
  if (alert.code.startsWith('table_')) return ` (table ${alert.channel})`

  return ` (canal ${alert.channel})`
}

/** Une phrase par alerte ; un code inconnu n'est jamais affiché tel quel. */
export function alertText(alert: ReplicationAlert, thresholds: ReplicationReport['thresholds']): string {
  const texts: Record<string, string> = {
    receiver_stopped: 'Le fil de réception est arrêté : le réplica ne reçoit plus les changements de la source.',
    receiver_connecting: 'Le fil de réception tente de joindre la source.',
    applier_stopped: 'Le fil d’application est arrêté : les changements reçus ne sont plus rejoués.',
    receiver_error: 'Le fil de réception a signalé une erreur : lisez-la ci-dessous.',
    applier_error: 'Le fil d’application a signalé une erreur : lisez-la avant de décider quoi que ce soit ; rien n’est exécuté par TerrilDB.',
    lag_high: `Le retard annoncé atteint ${formatSeconds(thresholds.warning)} ou plus (seuil : ${String(thresholds.warning)} s).`,
    lag_critical: `Le retard annoncé atteint ${formatSeconds(thresholds.critical)} ou plus.`,
    lag_unknown: 'Le retard n’est pas connu alors que le fil d’application tourne.',
    writable_replica: 'Ce réplica accepte les écritures : une écriture directe le ferait diverger de sa source.',
    binlog_format_not_row: 'Le format du journal binaire n’est pas ROW : les formats STATEMENT et MIXED peuvent faire diverger un réplica.',
    gtid_disabled: 'Les identifiants de transaction globaux (GTID) ne sont pas utilisés : le suivi et la bascule en sont plus fragiles.',
    slot_inactive: 'Ce slot n’est lu par personne mais retient du WAL : le disque du primaire se remplit tant que le slot existe.',
    slot_retains_wal: 'Ce slot retient beaucoup de WAL : un volume important de journal ne peut pas être recyclé.',
    slot_wal_unreserved: 'Le WAL de ce slot est menacé : s’il n’est pas rattrapé il sera supprimé au prochain point de reprise.',
    slot_wal_lost: 'Le WAL de ce slot est perdu : son consommateur ne peut plus reprendre et doit être reconstruit.',
    replica_not_streaming: 'Ce réplica ne reçoit pas le WAL en continu (rattrapage, démarrage ou lien interrompu).',
    replica_lag_high: `Ce réplica a un retard d’au moins ${formatSeconds(thresholds.warning)}.`,
    replica_lag_critical: `Ce réplica a un retard d’au moins ${formatSeconds(thresholds.critical)}.`,
    binlog_no_expiry: 'Les journaux binaires n’expirent jamais : ils s’accumulent sur le disque tant qu’on ne les purge pas à la main.',
    semisync_fallback: 'La réplication semi-synchrone est activée mais le serveur est repassé en asynchrone : une panne de la source peut perdre des transactions validées.',
    table_readonly: 'Cette table répliquée est en lecture seule : elle ne peut plus recevoir d’insertions.',
    table_session_expired: 'La session de cette table avec Keeper / ZooKeeper a expiré.',
    table_lag_high: `Cette table a un retard d’au moins ${formatSeconds(thresholds.warning)}.`,
    table_lag_critical: `Cette table a un retard d’au moins ${formatSeconds(thresholds.critical)}.`,
    table_queue_high: 'La file de réplication de cette table est longue : le réplica ne suit pas.',
    table_lost_parts: 'Cette table a perdu des parts de données : elles ne se retrouvent sur aucun réplica.',
    link_idle: 'La source n’a plus rien envoyé depuis longtemps : un silence prolongé précède souvent une coupure du lien.',
  }
  const text = texts[alert.code] ?? 'Alerte de réplication inconnue de cette version.'

  return `${text}${subject(alert)}`
}

/** Un retard annoncé de 0 alors que des transactions reçues attendent d'être appliquées est trompeur. */
export function lagNote(channel: ReplicationChannel): string | null {
  return channel.lagSeconds === 0 && channel.pendingApply === true
    ? 'Le retard annoncé (0 s) est trompeur : des transactions reçues ne sont pas encore appliquées. Comparez les jeux reçu et appliqué.'
    : null
}

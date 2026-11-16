import type { ReplicationAlert, ReplicationChannel, ReplicationReport, ReplicationRole, ThreadState } from '../api/types'
import { formatSeconds } from './diagnostics'

export function roleLabel(role: ReplicationRole): string {
  switch (role) {
    case 'none':
      return 'Pas de réplication configurée'
    case 'source':
      return 'Source'
    case 'replica':
      return 'Réplica'
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
  }
  const text = texts[alert.code] ?? 'Alerte de réplication inconnue de cette version.'

  return alert.channel === '' ? text : `${text} (canal ${alert.channel})`
}

/** Un retard annoncé de 0 alors que des transactions reçues attendent d'être appliquées est trompeur. */
export function lagNote(channel: ReplicationChannel): string | null {
  return channel.lagSeconds === 0 && channel.pendingApply === true
    ? 'Le retard annoncé (0 s) est trompeur : des transactions reçues ne sont pas encore appliquées. Comparez les jeux reçu et appliqué.'
    : null
}

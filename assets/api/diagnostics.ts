import { api } from './client'
import type { DiagnosticStatus, LockReport, SessionReport, VariableReport } from './types'

export interface DiagnosticScope {
  database: string | null
  schema: string | null
}

function suffix(scope: DiagnosticScope): string {
  const parts: string[] = []
  if (scope.database !== null) parts.push(`database=${encodeURIComponent(scope.database)}`)
  if (scope.schema !== null) parts.push(`schema=${encodeURIComponent(scope.schema)}`)

  return parts.length === 0 ? '' : `?${parts.join('&')}`
}

/** Ce que chaque source de supervision peut lire pour la connexion (sans lire les données). */
export async function fetchDiagnosticCapabilities(id: string, scope: DiagnosticScope): Promise<Record<string, DiagnosticStatus>> {
  const answer = await api<{ capabilities: Record<string, DiagnosticStatus> }>('GET', `/web/connections/${id}/diagnostics${suffix(scope)}`)

  return answer.capabilities
}

/** Les sessions en cours sur le serveur, avec l'état de la source (droits manquants, non pris en charge…). */
export const fetchSessions = (id: string, scope: DiagnosticScope): Promise<SessionReport> =>
  api<SessionReport>('GET', `/web/connections/${id}/diagnostics/sessions${suffix(scope)}`)

/** Transactions ouvertes et arbre « qui bloque qui » : lecture seule, rien n'est arrêté. */
export const fetchLocks = (id: string, scope: DiagnosticScope): Promise<LockReport> => api<LockReport>('GET', `/web/connections/${id}/diagnostics/locks${suffix(scope)}`)

export interface VariableFilter {
  search: string
  changedOnly: boolean
  limit: number
}

/** Variables de configuration du serveur, en lecture seule ; la valeur d'un secret n'est jamais servie. */
export function fetchVariables(id: string, scope: DiagnosticScope, filter: VariableFilter): Promise<VariableReport> {
  const parts: string[] = []
  if (filter.search !== '') parts.push(`q=${encodeURIComponent(filter.search)}`)
  if (filter.changedOnly) parts.push('changed=1')
  parts.push(`limit=${String(filter.limit)}`)
  if (scope.database !== null) parts.push(`database=${encodeURIComponent(scope.database)}`)
  if (scope.schema !== null) parts.push(`schema=${encodeURIComponent(scope.schema)}`)

  return api<VariableReport>('GET', `/web/connections/${id}/diagnostics/variables?${parts.join('&')}`)
}

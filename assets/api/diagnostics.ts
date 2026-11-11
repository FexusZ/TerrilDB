import { api } from './client'
import type { DiagnosticStatus, DigestOrder, DigestReport, LockReport, SessionReport } from './types'

export interface DiagnosticScope {
  database: string | null
  schema: string | null
}

function suffix(scope: DiagnosticScope, leading: string[] = []): string {
  const parts: string[] = [...leading]
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

/** Les requêtes les plus coûteuses par empreinte, classées et bornées (1 à 100) : lecture seule, rien n'est remis à zéro. */
export const fetchTopQueries = (id: string, scope: DiagnosticScope, order: DigestOrder, limit: number): Promise<DigestReport> =>
  api<DigestReport>('GET', `/web/connections/${id}/diagnostics/queries${suffix(scope, [`order=${order}`, `limit=${String(limit)}`])}`)

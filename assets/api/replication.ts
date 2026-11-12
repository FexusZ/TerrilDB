import { api } from './client'
import type { DiagnosticScope } from './diagnostics'
import type { ReplicationReport } from './types'

/** L'état de la réplication du serveur : lecture seule, aucune instruction de réplication n'est envoyée. */
export function fetchReplication(id: string, scope: DiagnosticScope): Promise<ReplicationReport> {
  const parts: string[] = []
  if (scope.database !== null) parts.push(`database=${encodeURIComponent(scope.database)}`)
  if (scope.schema !== null) parts.push(`schema=${encodeURIComponent(scope.schema)}`)

  return api<ReplicationReport>('GET', `/web/connections/${id}/diagnostics/replication${parts.length === 0 ? '' : `?${parts.join('&')}`}`)
}

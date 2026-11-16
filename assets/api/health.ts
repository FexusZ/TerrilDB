import { api } from './client'
import type { DiagnosticScope } from './diagnostics'
import type { DiagnosticStatus } from './types'

export type HealthLevel = 'ok' | 'warning' | 'unknown'

export interface HealthIndicator {
  key: string
  /** Null = inconnue (compteur absent, remis à zéro ou intervalle sans activité), jamais zéro. */
  value: number | null
  unit: 'per_second' | 'percent' | 'count'
  level: HealthLevel
  threshold: number | null
}

export interface HealthReport {
  status: DiagnosticStatus
  seconds: number | null
  startedAt: string | null
  endedAt: string | null
  /** `counters_reset` : le serveur a redémarré entre les deux relevés, la mesure est écartée. */
  discarded: string | null
  indicators: HealthIndicator[]
}

/** Deux relevés de compteurs à `seconds` secondes d'écart (1 à 30) : la requête dure autant. */
export function fetchHealth(id: string, scope: DiagnosticScope, seconds: number): Promise<HealthReport> {
  const parts = [`seconds=${String(seconds)}`]
  if (scope.database !== null) parts.push(`database=${encodeURIComponent(scope.database)}`)
  if (scope.schema !== null) parts.push(`schema=${encodeURIComponent(scope.schema)}`)

  return api<HealthReport>('GET', `/web/connections/${id}/diagnostics/health?${parts.join('&')}`)
}

import { api } from './client'
import { scopeParts, type DiagnosticScope } from './diagnostics'
import type { DiagnosticStatus } from './types'

export interface ErrorLogLine {
  loggedAt: string | null
  level: string
  subsystem: string | null
  code: string | null
  /** Déjà masqué (chaînes, adresses, comptes) et raccourci par le serveur. */
  message: string | null
}

export interface SlowQueryLine {
  startedAt: string | null
  user: string | null
  database: string | null
  durationMs: number | null
  lockMs: number | null
  rowsSent: number | null
  rowsExamined: number | null
  /** Littéraux remplacés par `?` ; nul quand illisible (`queryReason: redacted`). */
  query: string | null
  queryReason: string | null
}

export interface LogsReport {
  errorLog: { status: DiagnosticStatus; truncated: boolean; entries: ErrorLogLine[] }
  slowQueries: { status: DiagnosticStatus; truncated: boolean; thresholdSeconds: number | null; queries: SlowQueryLine[] }
}

/** Journal d'erreurs et requêtes lentes en table, lus en SQL, lecture seule. */
export function fetchLogs(id: string, scope: DiagnosticScope): Promise<LogsReport> {
  const parts: string[] = []
  parts.push(...scopeParts(scope))

  return api<LogsReport>('GET', `/web/connections/${id}/diagnostics/logs${parts.length === 0 ? '' : `?${parts.join('&')}`}`)
}

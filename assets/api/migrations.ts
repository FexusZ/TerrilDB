import { api, ApiError } from './client'
import type { DiagnosticScope } from './diagnostics'
import type { MigrationReport } from './types'

/** Les migrations de DDL en ligne du keyspace (Vitess) ; refusé (501) quand ce n'est pas un vtgate. */
export async function fetchMigrations(id: string, scope: DiagnosticScope): Promise<MigrationReport> {
  const parts: string[] = []
  if (scope.database !== null) parts.push(`database=${encodeURIComponent(scope.database)}`)
  if (scope.schema !== null) parts.push(`schema=${encodeURIComponent(scope.schema)}`)

  return api<MigrationReport>('GET', `/web/connections/${id}/diagnostics/migrations${parts.length === 0 ? '' : `?${parts.join('&')}`}`)
}

/**
 * Une action sur une migration n'a pas de route d'écriture propre : c'est une instruction `ALTER VITESS_MIGRATION` envoyée par la console,
 * donc jugée par le garde et soumise à sa confirmation. Sans `confirmed`, le serveur répond « confirmation requise » : on le dit à l'appelant.
 */
export async function runMigrationAction(id: string, statement: string, confirmed: boolean, scope: DiagnosticScope): Promise<'done' | 'needs_confirmation'> {
  try {
    await api('POST', `/web/connections/${id}/query`, { sql: statement, mode: 'read_write', confirmed, ...(scope.database === null ? {} : { database: scope.database }), ...(scope.schema === null ? {} : { schema: scope.schema }) })
  } catch (error) {
    if (!confirmed && error instanceof ApiError && error.code === 'confirmation_required') {
      return 'needs_confirmation'
    }
    throw error
  }

  return 'done'
}

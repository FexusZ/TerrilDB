import { api } from './client'
import type { AccountReport, ConfigAuditReport, ConnectionEncryption, DiagnosticStatus, DigestOrder, DigestReport, LockReport, SessionReport, VariableComparison, VariableReport } from './types'

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

/** Comptes, rôles et privilèges du serveur et l'audit de sécurité (MySQL, MariaDB) : lecture seule, jamais une empreinte de mot de passe. */
export const fetchAccounts = (id: string, scope: DiagnosticScope): Promise<AccountReport> => api<AccountReport>('GET', `/web/connections/${id}/diagnostics/accounts${suffix(scope)}`)

/** La connexion de TerrilDB vers la cible est-elle chiffrée ? Une observation. */
export const fetchEncryption = (id: string, scope: DiagnosticScope): Promise<ConnectionEncryption> => api<ConnectionEncryption>('GET', `/web/connections/${id}/diagnostics/encryption${suffix(scope)}`)

/** Audit de la configuration du serveur : des règles sur les variables lues, rien n'est exécuté. */
export const fetchConfigAudit = (id: string, scope: DiagnosticScope): Promise<ConfigAuditReport> => api<ConfigAuditReport>('GET', `/web/connections/${id}/diagnostics/config-audit${suffix(scope)}`)

/** Écart de variables entre deux connexions du même moteur : lecture seule, les variables masquées ne sont pas comparées. */
export const fetchVariableComparison = (a: string, b: string): Promise<VariableComparison> => api<VariableComparison>('GET', `/web/diagnostics/compare?a=${encodeURIComponent(a)}&b=${encodeURIComponent(b)}`)

import { api } from './client'
import type { AccountReport, ConnectionEncryption, DiagnosticStatus, LockReport, SessionReport } from './types'

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

/** Comptes, rôles et privilèges du serveur et l'audit de sécurité (MySQL, MariaDB) : lecture seule, jamais une empreinte de mot de passe. */
export const fetchAccounts = (id: string, scope: DiagnosticScope): Promise<AccountReport> => api<AccountReport>('GET', `/web/connections/${id}/diagnostics/accounts${suffix(scope)}`)

/** La connexion de TerrilDB vers la cible est-elle chiffrée ? Une observation. */
export const fetchEncryption = (id: string, scope: DiagnosticScope): Promise<ConnectionEncryption> => api<ConnectionEncryption>('GET', `/web/connections/${id}/diagnostics/encryption${suffix(scope)}`)

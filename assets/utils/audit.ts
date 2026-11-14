import type { AuditActor, AuditEntry } from '../api/types'

/** Filtres de la page d'activité : la valeur est la catégorie attendue par le serveur (vide = tout). */
export const ACTIVITY_FILTERS: { value: string; label: string }[] = [
  { value: '', label: 'Tout' },
  { value: 'login', label: 'Connexions' },
  { value: 'password', label: 'Mot de passe' },
  { value: 'totp', label: 'Double authentification' },
  { value: 'phrase', label: 'Phrase de récupération' },
  { value: 'connection', label: 'Bases' },
  { value: 'token', label: 'Jetons' },
  { value: 'target', label: 'Adresses refusées' },
  { value: 'invitation', label: 'Invitations' },
  { value: 'query', label: 'Requêtes' },
  { value: 'schema', label: 'Structure des tables' },
  { value: 'diagnostic', label: 'Supervision du serveur' },
]

const tableOf = (details: Record<string, unknown>): string => (typeof details.table === 'string' ? details.table : '?')
const quoted = (details: Record<string, unknown>): string => (typeof details.name === 'string' ? ` « ${details.name} »` : '')

export function describeEvent(entry: AuditEntry): string {
  const { details } = entry

  switch (entry.action) {
    case 'account.registered':
      return 'Compte créé'
    case 'login.success':
      return 'Connexion'
    case 'login.failure':
      return details.reason === 'invalid_second_factor' ? 'Échec de connexion : code de vérification incorrect' : 'Échec de connexion : identifiants incorrects'
    case 'login.throttled':
      return 'Connexions bloquées après trop de tentatives'
    case 'logout.done':
      return 'Déconnexion'
    case 'password.changed':
      return 'Mot de passe modifié'
    case 'password.failed':
      return 'Changement de mot de passe refusé : mot de passe actuel incorrect'
    case 'totp.enabled':
      return 'Double authentification activée'
    case 'totp.disabled':
      return entry.outcome === 'success' ? 'Double authentification désactivée' : 'Désactivation de la double authentification refusée'
    case 'recovery.regenerated':
      return entry.outcome === 'success' ? 'Codes de récupération régénérés' : 'Régénération des codes de récupération refusée'
    case 'recovery.used':
      return 'Code de récupération utilisé'
    case 'connection.created':
      return `Connexion${quoted(details)} créée`
    case 'connection.updated':
      return `Connexion${quoted(details)} modifiée`
    case 'connection.deleted':
      return `Connexion${quoted(details)} supprimée`
    case 'token.created':
      return `Jeton${quoted(details)} créé`
    case 'token.refreshed':
      return `Jeton${quoted(details)} actualisé`
    case 'target.forbidden':
      return typeof details.host === 'string' ? `Adresse réservée aux administrateurs refusée : ${details.host}` : 'Adresse réservée aux administrateurs refusée'
    case 'token.revoked':
      return 'Jeton révoqué'
    case 'phrase.created':
      return 'Phrase de récupération créée'
    case 'phrase.removed':
      return 'Phrase de récupération retirée'
    case 'phrase.used':
      return 'Mot de passe réinitialisé avec la phrase de récupération'
    case 'phrase.failed':
      return 'Phrase de récupération refusée'
    case 'invitation.created':
      return 'Invitation créée'
    case 'invitation.revoked':
      return 'Invitation révoquée'
    case 'query.executed':
      return 'Requête exécutée'
    case 'query.refused':
      return 'Requête refusée'
    case 'query.failed':
      return details.reason === 'timeout' ? 'Requête arrêtée : délai dépassé' : 'Requête en erreur'
    case 'schema.changed': {
      const count = typeof details.statements === 'number' ? details.statements : 0

      return `Structure de « ${tableOf(details)} » modifiée (${String(count)} ${count > 1 ? 'instructions' : 'instruction'})`
    }
    case 'schema.failed': {
      const applied = typeof details.applied === 'number' ? details.applied : 0

      return applied > 0
        ? `Modification de la structure de « ${tableOf(details)} » interrompue : ${String(applied)} ${applied > 1 ? 'instructions déjà appliquées' : 'instruction déjà appliquée'}`
        : `Modification de la structure de « ${tableOf(details)} » refusée par la base`
    }
    case 'diagnostic.read': {
      const topic = typeof details.topic === 'string' ? details.topic : '?'

      return entry.outcome === 'failed' ? `Lecture de la supervision en échec : ${topic}` : `Supervision lue : ${topic}`
    }
    default:
      return entry.action
  }
}

const ACTORS: Record<AuditActor, string> = { web: 'Interface', api: 'API', mcp: 'MCP', system: 'Système' }

export const actorLabel = (actor: AuditActor): string => ACTORS[actor]

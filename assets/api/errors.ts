import { ApiError } from './client'

const REASONS: Record<string, string> = {
  empty_statement: 'Saisissez une instruction.',
  multiple_statements: 'Exécutez une seule instruction à la fois.',
  incomplete_sql: 'L’instruction est incomplète : une chaîne ou un commentaire n’est pas refermé.',
  too_long: 'L’instruction est trop longue.',
  not_read_only: 'Cette instruction modifie la base : le mode lecture seule la refuse.',
  forbidden_construct: 'Cette instruction utilise une clause ou une fonction interdite (fichiers du serveur, verrous…).',
  unsupported_statement: 'Cette instruction n’est pas prise en charge (transaction, session, verrou…).',
  destructive_without_where: 'UPDATE ou DELETE sans clause WHERE : toutes les lignes seraient touchées.',
  destructive_statement: 'Cette instruction est destructive (suppression, modification de structure ou de droits).',
  destructive_schema_change: 'Cette modification détruit des données ou une structure : confirmez-la pour l’appliquer.',
}

const GENERIC = 'Une erreur est survenue. Réessayez.'

/** Explication d'un refus du garde. Un motif inconnu n'est jamais affiché tel quel. */
export function reasonText(reason: unknown): string {
  return (typeof reason === 'string' && REASONS[reason]) || 'Cette instruction n’est pas autorisée.'
}

/** Message à montrer à l'utilisateur pour une erreur de l'API. */
export function messageFor(error: unknown): string {
  if (!(error instanceof ApiError)) {
    return GENERIC
  }

  switch (error.code) {
    case 'invalid_credentials':
      return 'Identifiant ou mot de passe incorrect.'
    case 'invalid_second_factor':
      return 'Code de vérification incorrect ou déjà utilisé.'
    case 'second_factor_required':
      return 'Saisissez le code de votre application d’authentification.'
    case 'too_many_attempts':
      return `Trop de tentatives. Réessayez dans ${String(error.details.retryAfter ?? 'quelques')} s.`
    case 'weak_password':
      return 'Le mot de passe doit contenir au moins 12 caractères.'
    case 'account_exists':
      return 'Un compte existe déjà avec cet e-mail ou ce nom d’utilisateur.'
    case 'registration_closed':
      return 'Les inscriptions sont fermées : il faut une invitation.'
    case 'invalid_invitation':
      return 'Cette invitation n’est pas valable : elle a peut-être déjà servi, expiré ou été révoquée.'
    case 'invalid_recovery':
      return 'La phrase de récupération n’est pas valable, ou ce compte n’en a pas.'
    case 'mail_disabled':
      return 'L’envoi par e-mail n’est pas configuré sur ce serveur.'
    case 'forbidden':
      return 'Cette page est réservée aux administrateurs.'
    case 'locked':
    case 'unauthenticated':
      return 'Votre session doit être déverrouillée : reconnectez-vous.'
    case 'target_unreachable':
      return 'Impossible de joindre la base. Vérifiez l’hôte, le port et les identifiants.'
    case 'connection_name_used':
      return 'Vous avez déjà une connexion portant ce nom.'
    case 'totp_already_enabled':
      return 'La double authentification est déjà activée.'
    case 'totp_not_enabled':
      return 'La double authentification n’est pas activée.'
    case 'not_found':
      return 'Élément introuvable.'
    case 'unsupported_engine':
      return 'Ce moteur n’est pas encore pris en charge.'
    case 'forbidden_target':
      return 'Cette adresse désigne le serveur de TerrilDB lui-même : seul un administrateur peut la viser.'
    case 'unsupported_operation':
      return 'Cette opération n’existe pas pour ce moteur.'
    case 'diagnostics_failed':
      return 'La lecture de l’activité du serveur a échoué. Réessayez.'
    case 'plan_unavailable':
      return 'Le plan d’exécution n’est pas disponible pour ce moteur.'
    case 'query_refused':
    case 'confirmation_required':
      return reasonText(error.details.reason)
    case 'query_failed':
      return typeof error.details.message === 'string' ? error.details.message : GENERIC
    case 'invalid_schema_change':
      return 'Cette modification de la structure est invalide.'
    case 'schema_changed':
      return 'La table a été modifiée par ailleurs depuis son chargement. Rechargez-la avant de continuer.'
    case 'schema_change_failed':
      return typeof error.details.message === 'string' && error.details.message !== '' ? error.details.message : 'La base a refusé la modification de la structure.'
    case 'data_changed':
      return 'Une des lignes a été modifiée ou supprimée par ailleurs, ou ne se distingue pas d’une autre. Rechargez les données avant de continuer.'
    case 'data_change_failed':
      return typeof error.details.message === 'string' && error.details.message !== '' ? error.details.message : 'La base a refusé la modification des données.'
    case 'invalid_data_change':
      return 'Cette modification de données est invalide.'
    case 'query_timeout':
      return 'La requête a dépassé le délai autorisé.'
    case 'invalid_input':
    case 'bad_request':
      return 'Certaines informations sont invalides. Vérifiez le formulaire.'
    case 'network':
      return 'Impossible de joindre le serveur TerrilDB.'
    default:
      return GENERIC
  }
}

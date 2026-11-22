import { lockSentence } from '../utils/lock'
import type { StreamHit } from './types'
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
  destructive_script: 'Ce script contient des instructions destructrices : une seule confirmation les couvre toutes.',
  big_table_name: 'Cette table est très volumineuse : retapez le nom de la table pour confirmer.',
  other_administrator_token: 'Ce jeton appartient à un autre administrateur : sa révocation demande une confirmation explicite.',
  stream_source: 'Attention : cette modification touche une table qui nourrit un flux Vitess (Materialize, MoveTables). Le flux ne suivra pas ce changement. Pour tout garder à jour : faites d’abord le même changement sur la table cible du flux, puis appliquez-le ici. Pour un DROP, un RENAME ou un TRUNCATE, arrêtez ou annulez le flux avant (commandes Materialize ou MoveTables de vtctldclient), sinon il s’interrompt ; un flux MoveTables se termine ou s’annule avant de toucher sa source. TerrilDB ne lance rien de cela. Vérifiez la table cible ensuite.',
  destructive_schema_change: 'Cette modification détruit des données ou une structure : confirmez-la pour l’appliquer.',
}

const GENERIC = 'Une erreur est survenue. Réessayez.'

/** Explication d'un refus du garde. Un motif inconnu n'est jamais affiché tel quel. */
// Ce qui est REFUSÉ aux jetons d'API et au MCP demande, dans la console, une simple confirmation : le texte est alors un avertissement.
const WARNINGS: Record<string, string> = {
  stream_source: 'Attention : cette modification touche une table qui nourrit un flux Vitess (Materialize, MoveTables). Le flux ne suivra pas ce changement. Pour tout garder à jour : faites d’abord le même changement sur la table cible du flux, puis appliquez-le ici. Pour un DROP, un RENAME ou un TRUNCATE, arrêtez ou annulez le flux avant (commandes Materialize ou MoveTables de vtctldclient), sinon il s’interrompt ; un flux MoveTables se termine ou s’annule avant de toucher sa source. TerrilDB ne lance rien de cela. Vérifiez la table cible ensuite.',
  forbidden_construct: 'Attention : cette instruction sort du cadre habituel d’une requête (fichiers du serveur, verrous durables, état du serveur…). Le serveur de la base décidera selon les droits de votre compte.',
}

/** Les flux concernés, nommés : workflow, table touchée, tables remplies (rien d’inventé quand le serveur ne le dit pas). */
function streamSentence(streams: readonly StreamHit[]): string {
  const parts = streams.map((hit) => {
    const flow = hit.workflow === '' ? 'Un flux' : `Le flux « ${hit.workflow} »`
    const fills = hit.targets.length === 0 ? '' : ` et remplit ${hit.targets.join(', ')}`
    return `${flow} lit ${hit.table}${fills}.`
  })
  return parts.join(' ')
}

/** `confirming` : le texte d’une demande de confirmation (avertissement) plutôt que celui d’un refus. `streams` : les flux nommés par le serveur (motif `stream_source`). */
export function reasonText(reason: unknown, confirming = false, streams: readonly StreamHit[] = []): string {
  if (confirming && typeof reason === 'string' && WARNINGS[reason] !== undefined) {
    return reason === 'stream_source' && streams.length > 0 ? `${streamSentence(streams)} ${WARNINGS[reason]}` : WARNINGS[reason]
  }
  return (typeof reason === 'string' && REASONS[reason]) || 'Cette instruction n’est pas autorisée.'
}

function tunnelReason(reason: unknown): string {
  switch (reason) {
    case 'host_key_unconfirmed':
      return 'L’empreinte de la clé du bastion n’a pas été confirmée : lisez-la et confirmez-la dans la connexion.'
    case 'host_key_changed':
      return 'L’empreinte de la clé du bastion a changé : par prudence, le rebond est refusé. Vérifiez auprès de son administrateur avant de confirmer la nouvelle.'
    default:
      return 'Le rebond SSH n’a pas pu s’ouvrir. Vérifiez le bastion, l’identifiant et la clé.'
  }
}

/** Message à montrer à l'utilisateur pour une erreur de l'API. */
const PARTLY_APPLIED =
  'Un verrou a interrompu la modification : certaines instructions sont déjà appliquées (voir la liste). Ne la rejouez pas telle quelle : relisez la table, puis refaites ce qui manque.'

const FAULT_PARTLY_APPLIED =
  'La base a refusé une instruction : certaines sont déjà appliquées (voir la liste). Ne rejouez pas la modification telle quelle : relisez la table, puis refaites ce qui manque.'

function partlyApplied(error: ApiError): boolean {
  return error.details.retryable === false && Array.isArray(error.details.applied) && error.details.applied.length > 0
}

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
    case 'cannot_disable_self':
      return 'Vous ne pouvez pas désactiver votre propre compte.'
    case 'last_administrator':
      return 'Impossible : ce compte est le dernier administrateur actif.'
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
    case 'forbidden_bastion':
      return 'Ce bastion n’est pas autorisé : l’administrateur doit le déclarer avant qu’on puisse l’utiliser.'
    case 'replica_unavailable':
      return error.details.reason === 'not_vitess'
        ? 'Ce serveur n’est pas un Vitess : la lecture sur réplica n’existe pas ici.'
        : 'Vitess ne connaît aucun réplica en service pour cette base : il n’y a rien à lire. Créez-en un, puis réessayez.'
    case 'too_many_tunnels':
      return 'Trop de rebonds SSH sont ouverts pour votre compte : attendez qu’ils se referment (5 minutes d’inactivité) ou fermez-en.'
    case 'tunnel_failed':
      return tunnelReason(error.details.reason)
    case 'unsupported_operation':
      return 'Cette opération n’existe pas pour ce moteur.'
    case 'diagnostics_failed':
      return 'La lecture de l’activité du serveur a échoué. Réessayez.'
    case 'plan_unavailable':
      return 'Le plan d’exécution n’est pas disponible pour ce moteur.'
    case 'query_refused':
    case 'confirmation_required':
      return reasonText(error.details.reason)
    case 'script_refused': {
      const rank = typeof error.details.statement === 'number' ? error.details.statement + 1 : 0
      return `L’instruction ${String(rank)} est refusée : ${reasonText(error.details.reason)} Rien n’a été exécuté.`
    }
    case 'invalid_script':
      if (error.details.reason === 'delimiter_directive') {
        return 'La directive DELIMITER (une commande du client mysql, pas du moteur) n’est pas prise en charge : envoyez le corps de la procédure ou du déclencheur comme une seule instruction, sans DELIMITER. Rien n’a été exécuté.'
      }
      return 'Le script ne peut pas être découpé avec certitude : une chaîne, un commentaire ou un nom entre guillemets n’est pas refermé. Rien n’a été exécuté.'
    case 'script_too_large':
      return 'Le script est trop long (500 instructions et 1 Mo au plus).'
    case 'script_worker_unavailable':
      return 'Le worker des scripts en arrière-plan ne répond pas : le script n’est pas parti. Réessayez plus tard ou exécutez-le ici, sans arrière-plan.'
    case 'script_worker_busy':
      return 'Le worker joue déjà le nombre maximal de scripts : le script n’est pas parti, réessayez dans quelques secondes.'
    case 'run_finished':
      return 'Ce script est déjà terminé.'
    case 'dry_run_unavailable':
      if (error.details.reason === 'non_transactional_tables') {
        return 'Ce script écrit dans une table sans transaction (MyISAM, MEMORY…) : rien ne s’y annule, il ne peut pas être testé sans valider.'
      }
      return error.details.reason === 'implicit_commit'
        ? 'Ce moteur valide la transaction à chaque instruction de structure : ce script ne peut pas être testé sans valider.'
        : 'Ce moteur n’a pas de transaction : un script ne peut pas être testé sans valider.'
    case 'query_failed':
      return typeof error.details.message === 'string' ? error.details.message : GENERIC
    case 'invalid_schema_change':
      return 'Cette modification de la structure est invalide.'
    case 'unsupported_schema_change': {
      const why = lockSentence(error.details.reason)
      const subject = typeof error.details.subject === 'string' ? `« ${error.details.subject} » : ` : ''

      return why === null ? 'Cette modification n’est pas prise en charge pour une colonne ou un index à la définition particulière.' : `${subject}${why}, sa modification n’est pas prise en charge.`
    }
    case 'schema_changed':
      return 'La table a été modifiée par ailleurs depuis son chargement. Rechargez-la avant de continuer.'
    case 'schema_change_failed':
      return typeof error.details.message === 'string' && error.details.message !== '' ? error.details.message : 'La base a refusé la modification de la structure.'
    case 'unsupported_partition_change':
      return partitionRefusal(error.details.reason, error.details.subject)
    case 'data_changed':
      return 'Une des lignes a été modifiée ou supprimée par ailleurs, ou ne se distingue pas d’une autre. Rechargez les données avant de continuer.'
    case 'data_change_failed':
      return typeof error.details.message === 'string' && error.details.message !== '' ? error.details.message : 'La base a refusé la modification des données.'
    case 'invalid_data_change':
      return 'Cette modification de données est invalide.'
    case 'deadlock':
      if (partlyApplied(error)) return PARTLY_APPLIED
      return 'La base a détecté un interblocage avec une autre transaction et a annulé cette opération. Relancez-la telle quelle.'
    case 'serialization_failure':
      if (partlyApplied(error)) return PARTLY_APPLIED
      return 'La base a annulé cette opération parce qu’une modification simultanée l’empêchait de rester cohérente. Relancez-la telle quelle.'
    case 'lock_timeout':
      if (partlyApplied(error)) return PARTLY_APPLIED
      return 'Une autre transaction retient un verrou depuis trop longtemps et l’opération a cessé d’attendre. Laissez-la se terminer, puis relancez.'
    case 'too_many_connections':
      if (partlyApplied(error)) return FAULT_PARTLY_APPLIED
      return `Le serveur de base de données (ou ce compte) a atteint sa limite de connexions. Réessayez dans ${typeof error.details.retryAfter === 'number' ? `${String(error.details.retryAfter)} s` : 'quelques secondes'} ; si cela dure, fermez des sessions inutiles ou faites relever la limite.`
    case 'engine_permission_denied':
      if (partlyApplied(error)) return FAULT_PARTLY_APPLIED
      return 'Le compte de cette connexion n’a pas le droit d’effectuer cette opération sur la base. Demandez le droit à son administrateur ou utilisez un autre compte.'
    case 'duplicate_key':
      if (partlyApplied(error)) return FAULT_PARTLY_APPLIED
      return 'Une ligne avec la même valeur de clé ou d’index unique existe déjà : rien n’a été écrit. Changez la valeur ou modifiez la ligne existante.'
    case 'auth_requires_tls':
      return 'Le serveur exige une connexion chiffrée (TLS) et refuse celle-ci. Faites autoriser les connexions non chiffrées depuis TerrilDB ou utilisez le port TLS du serveur.'
    case 'auth_failed':
      return 'Le serveur a refusé les identifiants de cette connexion. Vérifiez l’identifiant et le mot de passe.'
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

function partitionRefusal(reason: unknown, subject: unknown): string {
  const name = typeof subject === 'string' && subject !== '' ? `« ${subject} » ` : ''
  switch (reason) {
    case 'partition_column':
      return `${name}sert au partitionnement de la table : on ne peut ni la retirer, ni la renommer, ni changer son type.`
    case 'partition_unique_key':
      return `${name}doit contenir toutes les colonnes de partitionnement : le moteur refuse une clé primaire ou unique qui ne les contient pas.`
    case 'partition_foreign_key':
      return `${name}: une clé étrangère n’est pas permise sur une table partitionnée par ce moteur.`
    default:
      return 'Cette modification n’est pas prise en charge sur une table partitionnée.'
  }
}

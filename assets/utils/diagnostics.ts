import type { BlockingNode, DiagnosticStatus, OpenTransaction, TableHolder } from '../api/types'

const REASONS: Record<string, string> = {
  performance_schema_off: 'performance_schema est éteint sur ce serveur',
  sys_schema_missing: 'le schéma sys est absent de ce serveur',
  extension_missing: 'L’extension pg_stat_statements n’est pas créée dans cette base',
  library_not_loaded: 'L’extension pg_stat_statements n’est pas préchargée par le serveur (shared_preload_libraries)',
  query_log_off: 'Le journal system.query_log est absent ou désactivé sur ce serveur ClickHouse',
}

const HINTS: Record<string, string> = {
  performance_schema_off: 'Démarrer le serveur avec performance_schema=ON (MariaDB le livre éteint, MySQL l’allume par défaut), puis redémarrer.',
  extension_missing: 'Dans la base, avec un compte autorisé : CREATE EXTENSION pg_stat_statements;',
  library_not_loaded: "Dans la configuration du serveur : shared_preload_libraries = 'pg_stat_statements', puis redémarrer PostgreSQL et lancer CREATE EXTENSION pg_stat_statements; dans la base.",
  query_log_off: 'Activer la section query_log de la configuration du serveur ClickHouse (activée par défaut dans la configuration d’origine).',
}

/** La façon d'allumer une source éteinte, à LIRE : TerrilDB ne l'exécute jamais. */
export const enableHint = (reason: string | null): string | null => (reason !== null && HINTS[reason] !== undefined ? HINTS[reason] : null)

/**
 * Pourquoi une source ne se lit pas, en français ; null quand elle se lit. Une source illisible n'est jamais présentée comme
 * « aucun résultat », et un motif inconnu n'est jamais affiché tel quel.
 */
export function describeStatus(status: DiagnosticStatus, topic: string): string | null {
  switch (status.availability) {
    case 'available':
      return null
    case 'permission_denied':
      return `Le compte de la connexion n’a pas le droit de lire ${topic}${status.privilege === null ? '' : ` : il lui manque ${status.privilege}`}.`
    case 'disabled':
      return `${status.reason !== null && REASONS[status.reason] !== undefined ? REASONS[status.reason] : 'La source est éteinte ou absente sur ce serveur'} : ne rien voir ici ne veut pas dire qu’il n’y a rien à voir.`
    case 'error':
      return `La vérification de ${topic} a échoué pour une cause imprévue : ne rien voir ici ne veut pas dire qu’il n’y a rien à voir. Réessayez, et consultez le journal du serveur si cela persiste.`
    case 'unsupported':
      return `Ce moteur ne fournit pas ${topic}.`
  }
}

/** Une durée en secondes, dans les deux plus grandes unités : 135 → « 2 min 15 s ». */
export function formatSeconds(total: number): string {
  const units: [string, number][] = [['j', 86400], ['h', 3600], ['min', 60], ['s', 1]]
  const parts: string[] = []
  let rest = Math.max(0, Math.floor(total))
  for (const [label, size] of units) {
    const count = Math.floor(rest / size)
    rest -= count * size
    if (count > 0 && parts.length < 2) {
      parts.push(`${String(count)} ${label}`)
    }
  }

  return parts.length === 0 ? '0 s' : parts.join(' ')
}

export const isLongTransaction = (transaction: OpenTransaction, thresholdSeconds: number): boolean => transaction.seconds >= thresholdSeconds

export interface BlockingLine {
  node: BlockingNode
  depth: number
}

/** L'arbre à plat, parcours en profondeur : une ligne par session, avec sa profondeur pour l'indentation. */
export function flattenBlocking(nodes: BlockingNode[], depth = 0): BlockingLine[] {
  return nodes.flatMap((node) => [{ node, depth }, ...flattenBlocking(node.blocked, depth + 1)])
}

/** Une session qui tient la table, en une ligne : « session 42 (alice), transaction ouverte depuis 2 min 15 s, inactive ». */
export function describeHolder(holder: TableHolder): string {
  const who = holder.user === null ? `session ${holder.session}` : `session ${holder.session} (${holder.user})`
  const age = holder.seconds === null ? '' : `, transaction ouverte depuis ${formatSeconds(holder.seconds)}`

  return `${who}${age}${holder.sleeping ? ', inactive' : ''}`
}

/** Une durée en millisecondes dans l'unité qui se lit : 0,4 ms, 12 ms, 1,5 s, 2 min 30 s. */
export function formatMs(ms: number): string {
  if (ms < 1) return `${ms.toFixed(1).replace('.', ',')} ms`
  if (ms < 1000) return `${String(Math.round(ms))} ms`
  if (ms < 60000) return `${(ms / 1000).toFixed(1).replace('.', ',').replace(/,0$/, '')} s`

  return formatSeconds(ms / 1000)
}

/** Lignes examinées pour une ligne renvoyée : « 1 000 », « 2,5 » ; vide si la source ne le dit pas. */
export function formatRatio(ratio: number | null): string {
  if (ratio === null) return ''
  const [whole = '0', decimals] = String(Math.round(ratio * 10) / 10).split('.')
  const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ' ')

  return decimals === undefined ? grouped : `${grouped},${decimals}`
}

/** Ce qu'un redémarrage change pour une variable ; vide quand le moteur ne le dit pas. */
export function describeRestart(restart: 'required' | 'pending' | null): string {
  switch (restart) {
    case 'required':
      return 'Redémarrage nécessaire'
    case 'pending':
      return 'Valeur en attente de redémarrage'
    case null:
      return ''
  }
}

const SOURCES: Record<string, string> = {
  COMPILED: 'défaut compilé',
  'COMPILE-TIME': 'défaut compilé',
  GLOBAL: 'fichier de configuration',
  EXPLICIT: 'fichier de configuration',
  CONFIG: 'fichier de configuration',
  'configuration file': 'fichier de configuration',
  COMMAND_LINE: 'ligne de commande',
  'COMMAND-LINE': 'ligne de commande',
  'command line': 'ligne de commande',
  PERSISTED: 'persistée',
  DYNAMIC: 'modifiée à chaud',
  SQL: 'modifiée à chaud',
  AUTO: 'calculée au démarrage',
  default: 'défaut',
  override: 'imposée par le serveur',
}

/** D'où vient une valeur, en français pour les origines courantes ; une origine inconnue reste telle que le moteur la dit. */
export const describeSource = (source: string | null): string => (source === null ? '' : (SOURCES[source] ?? source))

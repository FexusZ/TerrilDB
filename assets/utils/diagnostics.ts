import type { BlockingNode, DiagnosticStatus, OpenTransaction } from '../api/types'

const REASONS: Record<string, string> = {
  performance_schema_off: 'performance_schema est éteint sur ce serveur',
}

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

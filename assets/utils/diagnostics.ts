import type { BlockingNode, DiagnosticStatus, OpenTransaction, TableHolder } from '../api/types'

const REASONS: Record<string, string> = {
  performance_schema_off: 'performance_schema est éteint sur ce serveur',
  sys_schema_missing: 'le schéma sys est absent de ce serveur',
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
